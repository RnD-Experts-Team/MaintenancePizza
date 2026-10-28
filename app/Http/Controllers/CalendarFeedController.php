<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Maintenance's part of the unified calendar (calendar-feed/v1):
 *   tech_visit     one per assignment (a scheduled technician visit)
 *   ticket_opened  one per ticket opened in the range
 *
 * A fixed number of queries whatever the number of tickets — the dashboard today
 * makes one request per ticket to learn its visits.
 *
 * Who may see which stores is pizzasys's decision: the auth middleware forwards
 * the query string and the rule for this route reads `stores` from it (policy
 * "all"). We only refuse numbers we do not know, because pizzasys silently drops
 * those instead of refusing them.
 *
 * Times are UTC everywhere. `assigned_hour` has no zone in the database and is
 * sent as it is (10:00 → T10:00:00Z).
 *
 * Never sent: pay, parts and prices, private notes.
 */
class CalendarFeedController extends Controller
{
    private const MAX_STORES = 50;

    private const MAX_DAYS = 62;

    private const PRIORITY_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Date range may not exceed ' . self::MAX_DAYS . ' days.']);
        }

        // id => store_number
        $stores = DB::table('stores')->whereIn('store_number', $numbers)->pluck('store_number', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $events = array_merge(
            $this->visits($stores, $data['from'], $data['to']),
            $this->ticketsOpened($stores, $data['from'], $data['to']),
        );

        usort($events, fn ($a, $b) => [$a['date'], $a['start'] ?? '', $a['id']] <=> [$b['date'], $b['start'] ?? '', $b['id']]);

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'maintenance',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
            'events'       => $events,
            'meta'         => ['count' => count($events), 'truncated' => false],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function visits(Collection $stores, string $from, string $to): array
    {
        // One row per (assignment, issue). Mistaken assignments and "other
        // store" tickets (no store_id) never match.
        $rows = DB::table('assignments as a')
            ->join('assignment_ticket_issue as ati', 'ati.assignment_id', '=', 'a.id')
            ->join('ticket_issues as ti', 'ti.id', '=', 'ati.ticket_issue_id')
            ->join('tickets as t', 't.id', '=', 'ti.ticket_id')
            ->leftJoin('issues as i', 'i.id', '=', 'ti.issue_id')
            ->whereNull('t.deleted_at')
            ->whereIn('t.store_id', $stores->keys())
            ->where('a.mistaken', false)
            ->whereBetween('a.assigned_date', [$from, $to])
            ->orderBy('a.id')->orderBy('ti.id')
            ->get([
                'a.id as assignment_id', 'a.assigned_date', 'a.assigned_hour', 'a.updated_at',
                'ti.id as issue_id', 'ti.status', 'ti.priority', 'ti.assigned_priority', 'ti.other_title',
                'i.title as issue_title', 't.id as ticket_id', 't.store_id',
            ])
            ->groupBy('assignment_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $issueIds = $rows->flatten()->pluck('issue_id')->unique()->values();

        // ticket_issue_id => [technician, …]
        $technicians = DB::table('technician_ticket_issue as tti')
            ->join('technicians as tech', 'tech.id', '=', 'tti.technician_id')
            ->whereNull('tech.deleted_at')
            ->whereIn('tti.ticket_issue_id', $issueIds)
            ->get(['tti.ticket_issue_id', 'tech.id', 'tech.name'])
            ->groupBy('ticket_issue_id');

        // ticket_issue_id => [session, …]  (start_clock / end_clock are the
        // cached clock-in / clock-out of each session)
        $sessions = DB::table('attendance_entries as ae')
            ->join('attendance_entry_ticket_issue as aeti', 'aeti.attendance_entry_id', '=', 'ae.id')
            ->where('ae.mistaken', false)
            ->whereNotNull('ae.start_clock')
            ->whereIn('aeti.ticket_issue_id', $issueIds)
            ->get(['aeti.ticket_issue_id', 'ae.id', 'ae.start_clock', 'ae.end_clock'])
            ->groupBy('ticket_issue_id');

        // assignment_id => latest live reschedule
        $delays = DB::table('assignment_delays')
            ->whereIn('assignment_id', $rows->keys())
            ->where('mistaken', false)
            ->orderByDesc('id')
            ->get(['assignment_id', 'old_date', 'old_hour', 'reason'])
            ->unique('assignment_id')
            ->keyBy('assignment_id');

        $today = Carbon::now('UTC')->toDateString();
        $events = [];

        foreach ($rows as $assignmentId => $issues) {
            $first = $issues->first();
            $date = Carbon::parse($first->assigned_date)->toDateString();
            $hour = $first->assigned_hour ? substr($first->assigned_hour, 0, 5) : null;

            // Sessions on this visit's day, on any of its issues.
            $visitSessions = $issues->flatMap(fn ($i) => $sessions->get($i->issue_id, collect()))
                ->unique('id')
                ->filter(fn ($s) => Carbon::parse($s->start_clock)->toDateString() === $date);
            $checkIn = $visitSessions->min('start_clock');
            $checkOut = $visitSessions->isNotEmpty() && $visitSessions->every(fn ($s) => $s->end_clock !== null)
                ? $visitSessions->max('end_clock')
                : null;

            $status = match (true) {
                $issues->every(fn ($i) => $i->status === 'cancelled') => 'cancelled',
                $checkOut !== null                                    => 'done',
                $checkIn !== null                                     => 'in_progress',
                $date < $today                                        => 'missed',
                default                                               => 'scheduled',
            };

            $people = $issues->flatMap(fn ($i) => $technicians->get($i->issue_id, collect()))
                ->unique('id')
                ->map(fn ($t) => ['kind' => 'technician', 'id' => (int) $t->id, 'name' => mb_substr($t->name, 0, 60)])
                ->values()->all();

            $names = $issues->map(fn ($i) => $i->issue_title ?? $i->other_title ?? 'Issue')->unique()->values();
            $title = 'Visit: ' . $names->first() . ($names->count() > 1 ? ' +' . ($names->count() - 1) : '');

            // The highest priority across the visit's issues; the one staff
            // assigned wins over the one it was created with.
            $priority = $issues->map(fn ($i) => $i->assigned_priority ?: $i->priority)
                ->sortByDesc(fn ($p) => self::PRIORITY_RANK[$p] ?? 0)
                ->first();

            $delay = $delays->get($assignmentId);

            $events[] = [
                'id'         => 'maintenance:tech_visit:' . $assignmentId,
                'type'       => 'tech_visit',
                'layer'      => 'maintenance',
                'store'      => $stores[$first->store_id],
                'title'      => mb_substr($title, 0, 120),
                'all_day'    => $hour === null,
                'date'       => $date,
                'date_end'   => null,
                'start'      => $hour === null ? null : $date . 'T' . $hour . ':00Z',
                'end'        => null,
                'status'     => $status,
                'people'     => $people,
                'link'       => '/dashboard/maintenance-tickets/' . $first->ticket_id . '?store=' . $stores[$first->store_id],
                'updated_at' => $this->utc($first->updated_at),
                'extra'      => [
                    'ticket_id'        => (int) $first->ticket_id,
                    'issue_ids'        => $issues->pluck('issue_id')->map(fn ($id) => (int) $id)->values()->all(),
                    'priority'         => $priority,
                    'any_time'         => $hour === null,
                    'rescheduled_from' => $delay ? [
                        'date'   => Carbon::parse($delay->old_date)->toDateString(),
                        'hour'   => $delay->old_hour ? substr($delay->old_hour, 0, 5) : null,
                        'reason' => mb_substr((string) $delay->reason, 0, 200),
                    ] : null,
                    'check_in'         => $this->utc($checkIn),
                    'check_out'        => $this->utc($checkOut),
                ],
            ];
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function ticketsOpened(Collection $stores, string $from, string $to): array
    {
        return DB::table('tickets')
            ->whereNull('deleted_at')
            ->whereIn('store_id', $stores->keys())
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('id')
            ->get(['id', 'store_id', 'created_at', 'updated_at'])
            ->map(fn ($t) => [
                'id'         => 'maintenance:ticket_opened:' . $t->id,
                'type'       => 'ticket_opened',
                'layer'      => 'maintenance',
                'store'      => $stores[$t->store_id],
                'title'      => 'New ticket #' . $t->id,
                'all_day'    => false,
                'date'       => Carbon::parse($t->created_at)->toDateString(),
                'date_end'   => null,
                'start'      => $this->utc($t->created_at),
                'end'        => null,
                'status'     => 'info',
                'people'     => [],
                'link'       => '/dashboard/maintenance-tickets/' . $t->id . '?store=' . $stores[$t->store_id],
                'updated_at' => $this->utc($t->updated_at),
                'extra'      => ['ticket_id' => (int) $t->id],
            ])
            ->all();
    }

    private function utc($at): ?string
    {
        return $at ? Carbon::parse($at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
