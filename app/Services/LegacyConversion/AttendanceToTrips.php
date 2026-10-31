<?php

namespace App\Services\LegacyConversion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Old attendance entries become trips.
 *
 * One entry becomes one trip of its technician. Its clock-in / clock-out
 * events become sessions (an entry clocked out and back in becomes several),
 * its break / travel / parts-run events go into the session they happened in,
 * and its ticket issues become the trip's stops -- one per store -- so the
 * trip shows where the hours were spent.
 *
 * An entry still on the clock stays open, except that a technician can only
 * be clocked in once: a second open entry keeps its missing clock-out (shown
 * as "never clocked out") and is reported.
 */
final class AttendanceToTrips extends Converter
{
    public function run(): void
    {
        DB::table('attendance_entries')->orderBy('id')->chunkById(200, function ($entries) {
            foreach ($entries as $entry) {
                if (LegacyMap::has('attendance_entries', (int) $entry->id, 'trips')) {
                    continue;
                }

                DB::transaction(fn () => $this->convert($entry));
            }
        });
    }

    private function convert(object $entry): void
    {
        $events = DB::table('attendance_events')
            ->where('attendance_entry_id', $entry->id)
            ->orderBy('at')->orderBy('id')
            ->get();

        $spans = $this->sessionSpans($entry, $events);
        $first = $spans[0]['in'] ?? $entry->start_clock ?? $entry->created_at;
        $open = $spans !== [] && end($spans)['out'] === null && ! $entry->mistaken;

        $tripId = (int) DB::table('trips')->insertGetId([
            'technician_id' => $entry->technician_id,
            'status' => $open ? 'open' : 'closed',
            'planned_date' => Carbon::parse($first)->toDateString(),
            'closed_at' => $open ? null : (end($spans)['out'] ?? $entry->end_clock ?? $entry->updated_at),
            'mistaken' => $entry->mistaken,
            'created_by' => $entry->created_by,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ]);
        LegacyMap::put('attendance_entries', (int) $entry->id, 'trips', $tripId);

        $sessionIds = [];
        $last = array_key_last($spans);
        foreach ($spans as $i => $span) {
            // Only the LAST span can still be running: an earlier one with no
            // clock-out was followed by another clock-in, so it is a session
            // somebody never clocked out of, not a live one.
            $isLive = $span['out'] === null && ! $entry->mistaken && $i === $last;
            $marker = null;

            if ($span['out'] === null && ! $isLive && ! $entry->mistaken) {
                LegacyMap::report('trips', "Attendance entry #{$entry->id} was clocked in again without clocking out; the earlier session is kept without a clock-out", ['attendance_entry_id' => $entry->id], 'warning');
            }

            if ($isLive) {
                $taken = DB::table('trip_sessions')->where('technician_id', $entry->technician_id)->where('open_marker', true)->exists();
                $marker = $taken ? null : true;

                if ($taken) {
                    LegacyMap::report('trips', "Attendance entry #{$entry->id} was still on the clock while the same technician had another open entry; kept without a clock-out", ['attendance_entry_id' => $entry->id], 'warning');
                }
            }

            $sessionId = (int) DB::table('trip_sessions')->insertGetId([
                'trip_id' => $tripId,
                'technician_id' => $entry->technician_id,
                'clock_in' => $span['in'],
                'clock_out' => $span['out'],
                'open_marker' => $marker,
                'source' => 'migrated',
                'mistaken' => $entry->mistaken,
                'created_by' => $entry->created_by,
                'created_at' => $entry->created_at,
                'updated_at' => $entry->updated_at,
            ]);
            $sessionIds[$i] = $sessionId;
            LegacyMap::put('attendance_entries', (int) $entry->id, 'trip_sessions', $sessionId);
        }

        if ($spans === []) {
            LegacyMap::report('trips', "Attendance entry #{$entry->id} had no clock-in at all; its trip has no session", ['attendance_entry_id' => $entry->id], 'warning');
        }

        foreach ($events as $event) {
            if (in_array($event->kind, ['clock_in', 'clock_out'], true) || $sessionIds === []) {
                continue;
            }

            DB::table('trip_events')->insert([
                'trip_session_id' => $sessionIds[$this->spanIndexFor($spans, $event->at)],
                'kind' => $event->kind,
                'at' => $event->at,
                'mistaken' => $event->mistaken,
                'created_by' => $event->created_by,
                'created_at' => $event->created_at,
                'updated_at' => $event->updated_at,
            ]);
        }

        $this->stops($entry, $tripId, $first, $open);
        $this->moveMorphs('App\\Models\\AttendanceEntry', (int) $entry->id, 'App\\Models\\Trip', $tripId);
    }

    /**
     * Clock-in → clock-out pairs, from the non-mistaken clock events; the old
     * cached start/end columns when an entry predates the event ledger.
     *
     * @return list<array{in: string, out: string|null}>
     */
    private function sessionSpans(object $entry, $events): array
    {
        $spans = [];
        $openAt = null;

        foreach ($events as $event) {
            if ($event->mistaken) {
                continue;
            }

            if ($event->kind === 'clock_in') {
                if ($openAt !== null) {
                    $spans[] = ['in' => $openAt, 'out' => null];
                }
                $openAt = $event->at;
            } elseif ($event->kind === 'clock_out' && $openAt !== null) {
                $spans[] = ['in' => $openAt, 'out' => $event->at];
                $openAt = null;
            }
        }

        if ($openAt !== null) {
            $spans[] = ['in' => $openAt, 'out' => null];
        }

        if ($spans === [] && $entry->start_clock !== null) {
            $spans[] = ['in' => $entry->start_clock, 'out' => $entry->end_clock];
        }

        return $spans;
    }

    /** @param  list<array{in: string, out: string|null}>  $spans */
    private function spanIndexFor(array $spans, string $at): int
    {
        $index = 0;

        foreach ($spans as $i => $span) {
            if (strcmp($span['in'], $at) <= 0) {
                $index = $i;
            }
        }

        return $index;
    }

    private function stops(object $entry, int $tripId, ?string $arrivedAt, bool $open): void
    {
        $issueIds = DB::table('attendance_entry_ticket_issue')
            ->where('attendance_entry_id', $entry->id)
            ->pluck('ticket_issue_id')
            ->map(fn ($id) => (int) $id);

        $byStore = [];
        foreach ($issueIds as $issueId) {
            [$storeId, $other] = $this->storeOfIssue($issueId);
            $byStore[$storeId !== null ? "s{$storeId}" : 'o'.($other ?? '')][] = ['issue' => $issueId, 'store' => $storeId, 'other' => $other];
        }

        $order = 0;
        foreach ($byStore as $group) {
            $stopId = (int) DB::table('trip_stops')->insertGetId([
                'trip_id' => $tripId,
                'store_id' => $group[0]['store'],
                'other_store' => $group[0]['store'] === null ? ($group[0]['other'] ?? 'Unknown store') : null,
                // Still on the clock: he is there, not finished.
                'status' => $open ? 'arrived' : 'done',
                'arrived_at' => $arrivedAt,
                'sort_order' => $order++,
                'created_at' => $entry->created_at,
                'updated_at' => $entry->updated_at,
            ]);

            foreach ($group as $row) {
                $closed = DB::table('ticket_issues')->where('id', $row['issue'])->whereIn('status', ['closed', 'cancelled', 'work_done'])->exists();

                DB::table('trip_stop_issues')->insert([
                    'trip_stop_id' => $stopId,
                    'ticket_issue_id' => $row['issue'],
                    'purpose' => 'work',
                    'task_status' => $closed ? 'done' : 'pending',
                    'created_at' => $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ]);
            }
        }
    }
}
