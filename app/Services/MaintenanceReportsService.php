<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Enums\PaymentLineType;
use App\Enums\Priority;
use App\Models\TicketIssue;
use App\Models\TripSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/maintenance-reports: the weekly maintenance reports, in one answer.
 *
 *   weekly_report     every issue submitted in the range
 *   pending_report    every issue still outstanding, whenever it was
 *                     submitted (up to the end date)
 *   technical_issues  catalog issue TECHNICAL_ISSUE_ID: submitted in the
 *                     range, plus every one still outstanding from before
 *   status_report     counts and average waiting days by status, outstanding
 *                     by priority, and by issue type
 *   payment_report    technician pay in the range, by store and category and
 *                     by technician
 *
 * STATUSES are the workflow's own (IssueStatus), as they are. Outstanding =
 * not closed and not cancelled. How a closed issue ended (deferred, ...) is
 * its resolution, on the row.
 *
 * WAITING DAYS. Calendar days from the day the issue was submitted to the
 * day it finished (closed or cancelled), or to today while it is outstanding.
 *
 * YTD. From January 1 of the end date's year to the end date. A status that
 * ends an issue (closed, cancelled) counts the issues that ended in the
 * period; an open status counts the issues submitted in the period that are
 * in it now.
 *
 * SUBMITTED BY MOS. This service keeps no roles, so a submitter is MOS when
 * they do the MOS's work here: they have recorded a payment, a trip or a
 * booking (all MOS-only actions).
 */
class MaintenanceReportsService
{
    /** The catalog issue of the Technical Issues report. */
    public const TECHNICAL_ISSUE_ID = 151;

    public function __construct(private TripService $trips)
    {
    }

    /**
     * @param  Carbon  $start  first day, included
     * @param  Carbon  $end  last day, included
     * @return array<string, mixed>
     */
    public function report(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $endExclusive = $end->copy()->startOfDay()->addDay();
        $ytdStart = $end->copy()->startOfYear();
        $today = now()->startOfDay();

        $issues = $this->issues($start->min($ytdStart), $endExclusive, $today);

        $inRange = fn (?Carbon $at) => $at !== null && $at->gte($start) && $at->lt($endExclusive);
        $inYtd = fn (?Carbon $at) => $at !== null && $at->gte($ytdStart) && $at->lt($endExclusive);

        $submitted = $issues->filter(fn (array $i) => $inRange($i['submitted_at']))->values();

        return [
            'range' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'ytd_start_date' => $ytdStart->toDateString(),
                'today' => $today->toDateString(),
            ],
            'weekly_report' => $this->rows($submitted),
            'pending_report' => $this->rows($issues->where('outstanding', true)),
            'technical_issues' => $this->rows($issues->filter(
                fn (array $i) => $i['issue_id'] === self::TECHNICAL_ISSUE_ID && ($i['outstanding'] || $inRange($i['submitted_at']))
            )),
            'status_report' => [
                'statuses' => $this->statuses($issues, $inRange, $inYtd),
                'outstanding_by_priority' => [
                    'ytd' => $this->byPriority($issues->filter(fn (array $i) => $i['outstanding'] && $inYtd($i['submitted_at']))),
                    'range' => $this->byPriority($submitted->where('outstanding', true)),
                ],
                'by_issue_type' => $this->byIssueType($submitted),
            ],
            'payment_report' => $this->payments($start, $endExclusive),
        ];
    }

    // ---------------------------------------------------------------- Issues

    /**
     * Every issue a report can need: submitted before the end, and either
     * submitted since $from, still outstanding, or finished since $from.
     * Issues of deleted tickets are left out.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function issues(Carbon $from, Carbon $endExclusive, Carbon $today): Collection
    {
        $terminal = array_map(fn (IssueStatus $s) => $s->value, IssueStatus::terminal());
        $mos = $this->mosUserIds();

        return TicketIssue::query()
            ->whereHas('ticket')
            ->with(['ticket.store', 'ticket.storageLocation', 'issue' => fn ($q) => $q->withTrashed()])
            ->where('created_at', '<', $endExclusive)
            ->where(fn ($q) => $q->where('created_at', '>=', $from)
                ->orWhereNotIn('status', $terminal)
                ->orWhere('closed_at', '>=', $from))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketIssue $issue) => $this->issue($issue, $today, $mos));
    }

    /**
     * @param  array<int, true>  $mos
     * @return array<string, mixed>
     */
    private function issue(TicketIssue $issue, Carbon $today, array $mos): array
    {
        $outstanding = ! $issue->status->isTerminal();
        $finishedAt = $outstanding ? null : ($issue->closed_at ?? $issue->updated_at);
        $submittedAt = $issue->created_at;

        $priority = $issue->assigned_priority ?? $issue->priority;
        $submitter = $issue->created_by ?? $issue->ticket->created_by;

        return [
            'issue_id' => $issue->issue_id === null ? null : (int) $issue->issue_id,
            'title' => $issue->displayTitle(),
            'submitted_at' => $submittedAt,
            'finished_at' => $finishedAt,
            'outstanding' => $outstanding,
            'status' => $issue->status,
            'priority' => $priority,
            'waiting_days' => (int) $submittedAt->copy()->startOfDay()->diffInDays(
                ($finishedAt ?? $today)->copy()->startOfDay()->max($submittedAt->copy()->startOfDay())
            ),
            'row' => [
                'ticket_id' => (int) $issue->ticket_id,
                'ticket_issue_id' => (int) $issue->id,
                'issue_id' => $issue->issue_id === null ? null : (int) $issue->issue_id,
                'issue_date' => $submittedAt->toDateString(),
                'store_number' => $issue->ticket->placeLabel(),
                'issue' => $issue->displayTitle(),
                'priority' => $priority === null ? null : ['value' => $priority->value, 'label' => $priority->label()],
                'submitted_by_mos' => $submitter === null ? null : isset($mos[(int) $submitter]),
                'status' => ['value' => $issue->status->value, 'label' => $issue->status->label()],
                'resolution' => $issue->resolution === null ? null : ['value' => $issue->resolution->value, 'label' => $issue->resolution->label()],
                'description' => $issue->description,
            ],
        ];
    }

    /**
     * The users who do the MOS's work in this service, as a set.
     *
     * @return array<int, true>
     */
    private function mosUserIds(): array
    {
        return DB::table('payments')->whereNotNull('created_by')->select('created_by')
            ->union(DB::table('trips')->whereNotNull('created_by')->select('created_by'))
            ->union(DB::table('assignments')->whereNotNull('created_by')->select('created_by'))
            ->pluck('created_by')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $issues): array
    {
        return $issues->map(fn (array $i) => $i['row'] + ['waiting_days' => $i['waiting_days']])->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function statuses(Collection $issues, \Closure $inRange, \Closure $inYtd): array
    {
        $rows = [
            ['weekly_received', 'Weekly Received Tickets', fn (array $i) => $inRange($i['submitted_at'])],
            ['closed_weekly', 'Closed Weekly', fn (array $i) => $i['status'] === IssueStatus::Closed && $inRange($i['finished_at'])],
        ];
        // Every workflow status, year to date: a finishing status by when the
        // issue finished, an open one by when it was submitted.
        foreach (IssueStatus::cases() as $status) {
            $rows[] = [
                $status->value.'_ytd',
                $status->label().' YTD',
                fn (array $i) => $i['status'] === $status
                    && $inYtd($status->isTerminal() ? $i['finished_at'] : $i['submitted_at']),
            ];
        }
        $rows[] = ['total_outstanding', 'Total Outstanding', fn (array $i) => $i['outstanding']];

        return array_map(fn (array $r) => ['key' => $r[0], 'label' => $r[1]] + $this->countAndWait($issues->filter($r[2])), $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function byPriority(Collection $issues): array
    {
        return array_map(fn (Priority $p) => [
            'priority' => $p->value,
            'label' => $p->label(),
        ] + $this->countAndWait($issues->filter(fn (array $i) => $i['priority'] === $p)), Priority::cases());
    }

    /**
     * The issues submitted in the range, by catalog issue ("other" issues,
     * typed in by the store, together), most received first.
     *
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private function byIssueType(Collection $issues): array
    {
        $rows = $issues->groupBy(fn (array $i) => $i['issue_id'] ?? 'other')
            ->map(fn (Collection $group) => [
                'issue_id' => $group->first()['issue_id'],
                'issue' => $group->first()['issue_id'] === null ? 'Other' : $group->first()['title'],
            ] + $this->shares($group))
            ->sortBy([['received', 'desc'], ['issue', 'asc']])
            ->values()
            ->all();

        return ['rows' => $rows, 'total' => $this->shares($issues)];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private function shares(Collection $issues): array
    {
        $received = $issues->count();
        $count = fn (\Closure $f) => $issues->filter($f)->count();
        $pct = fn (int $n) => $received === 0 ? null : round($n / $received * 100, 1);

        $closed = $count(fn (array $i) => $i['status'] === IssueStatus::Closed);
        $outstanding = $count(fn (array $i) => $i['outstanding']);
        $cancelled = $count(fn (array $i) => $i['status'] === IssueStatus::Cancelled);

        return [
            'received' => $received,
            'closed' => $closed,
            'outstanding' => $outstanding,
            'cancelled' => $cancelled,
            'closed_pct' => $pct($closed),
            'outstanding_pct' => $pct($outstanding),
            'cancelled_pct' => $pct($cancelled),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array{issues: int, avg_waiting_days: float|null}
     */
    private function countAndWait(Collection $issues): array
    {
        return [
            'issues' => $issues->count(),
            'avg_waiting_days' => $issues->isEmpty() ? null : round($issues->avg('waiting_days'), 1),
        ];
    }

    // ---------------------------------------------------------------- Payments

    /**
     * What technicians were paid in the range (payments dated in it, mistaken
     * ones left out, refunds netted), by store and category and by technician.
     * Supplier payments are not technician pay and are not here.
     *
     * Money is summed from the lines by type: hourly, fixed, inspection,
     * parts, gas and other (the old sheet's "money owed"). `hours` are the
     * hourly lines' paid hours, travel included; `travel_hours` is the part of
     * the paid sessions spent driving. `hourly_rate` is the hourly money over
     * the hours (the average when rates differ).
     *
     * @return array<string, mixed>
     */
    private function payments(Carbon $start, Carbon $endExclusive): array
    {
        $lines = DB::table('payment_lines as pl')
            ->join('payments as p', 'p.id', '=', 'pl.payment_id')
            ->join('technicians as t', 't.id', '=', 'p.technician_id')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('stores as s', 's.id', '=', 'pl.store_id')
            ->leftJoin('storage_locations as sl', 'sl.id', '=', 'pl.storage_location_id')
            ->where('p.mistaken', false)
            ->where('p.paid_at', '>=', $start->toDateString())
            ->where('p.paid_at', '<', $endExclusive->toDateString())
            ->select([
                'pl.id', 'pl.type', 'pl.amount', 'pl.hours', 'pl.store_id', 'pl.other_store', 'pl.storage_location_id',
                's.store_number', 'sl.name as storage_name',
                't.id as technician_id', 't.name as technician_name', 'c.id as category_id', 'c.name as category_name',
            ])
            ->orderBy('pl.id')
            ->get();

        $travel = $this->travelMinutesByLine($lines->pluck('id')->all());

        $byStore = [];
        $byTechnician = [];
        $total = $this->emptyFigures();

        foreach ($lines as $line) {
            $minutes = $travel[(int) $line->id] ?? 0;
            $category = $line->category_id === null ? null : ['id' => (int) $line->category_id, 'name' => $line->category_name];
            [$placeKey, $place] = $this->place($line);

            $storeKey = $placeKey.'|'.($line->category_id ?? '-');
            $byStore[$storeKey] ??= ['store' => $place, 'category' => $category, 'figures' => $this->emptyFigures()];
            $this->add($byStore[$storeKey]['figures'], $line, $minutes);

            $technicianKey = (int) $line->technician_id;
            $byTechnician[$technicianKey] ??= [
                'technician' => ['id' => $technicianKey, 'name' => $line->technician_name],
                'category' => $category,
                'figures' => $this->emptyFigures(),
            ];
            $this->add($byTechnician[$technicianKey]['figures'], $line, $minutes);

            $this->add($total, $line, $minutes);
        }

        $present = fn (array $row) => array_diff_key($row, ['figures' => true]) + $this->present($row['figures']);

        return [
            'by_store' => collect($byStore)
                ->sortBy([
                    fn ($a, $b) => strnatcasecmp((string) $a['store']['label'], (string) $b['store']['label']),
                    fn ($a, $b) => strnatcasecmp((string) ($a['category']['name'] ?? '~'), (string) ($b['category']['name'] ?? '~')),
                ])
                ->map($present)->values()->all(),
            'by_technician' => collect($byTechnician)
                ->sortBy(fn ($r) => mb_strtolower((string) $r['technician']['name']))
                ->map($present)->values()->all(),
            'total' => $this->present($total),
        ];
    }

    /**
     * Where a line's money went: a store, a storage location, or a place in words.
     *
     * @return array{0: string, 1: array{type: string, id: int|null, label: string|null}}
     */
    private function place(object $line): array
    {
        if ($line->store_id !== null) {
            return ['s'.$line->store_id, ['type' => 'store', 'id' => (int) $line->store_id, 'label' => $line->store_number]];
        }
        if ($line->storage_location_id !== null) {
            return ['l'.$line->storage_location_id, ['type' => 'storage_location', 'id' => (int) $line->storage_location_id, 'label' => $line->storage_name]];
        }

        return ['o'.mb_strtolower((string) $line->other_store), ['type' => 'other', 'id' => null, 'label' => $line->other_store]];
    }

    /**
     * Minutes spent driving in the sessions each line paid.
     *
     * @param  array<int, int>  $lineIds
     * @return array<int, int>
     */
    private function travelMinutesByLine(array $lineIds): array
    {
        $minutes = [];

        foreach (array_chunk($lineIds, 500) as $chunk) {
            $links = DB::table('payment_line_trip_session')->whereIn('payment_line_id', $chunk)->get(['payment_line_id', 'trip_session_id']);
            if ($links->isEmpty()) {
                continue;
            }

            $sessions = TripSession::query()
                ->with('events')
                ->whereIn('id', $links->pluck('trip_session_id')->unique()->all())
                ->where('mistaken', false)
                ->whereNotNull('clock_out')
                ->get()
                ->keyBy('id');

            foreach ($links as $link) {
                $session = $sessions->get((int) $link->trip_session_id);
                if ($session !== null) {
                    $minutes[(int) $link->payment_line_id] = ($minutes[(int) $link->payment_line_id] ?? 0)
                        + $this->trips->durations($session)['travel'];
                }
            }
        }

        return $minutes;
    }

    /** @return array<string, float|int> */
    private function emptyFigures(): array
    {
        return [
            'hours' => 0.0, 'hourly' => 0.0, 'travel_minutes' => 0,
            'gas' => 0.0, 'money_owed' => 0.0, 'fixed' => 0.0, 'inspection' => 0.0, 'parts' => 0.0, 'total' => 0.0,
        ];
    }

    /** @param  array<string, float|int>  $f */
    private function add(array &$f, object $line, int $travelMinutes): void
    {
        $amount = (float) $line->amount;

        $bucket = match (PaymentLineType::from($line->type)) {
            PaymentLineType::Hourly => 'hourly',
            PaymentLineType::Gas => 'gas',
            PaymentLineType::Other => 'money_owed',
            PaymentLineType::Fixed => 'fixed',
            PaymentLineType::Inspection => 'inspection',
            PaymentLineType::Parts => 'parts',
        };

        $f[$bucket] += $amount;
        $f['total'] += $amount;
        $f['travel_minutes'] += $travelMinutes;
        if ($bucket === 'hourly') {
            $f['hours'] += (float) $line->hours;
        }
    }

    /**
     * @param  array<string, float|int>  $f
     * @return array<string, mixed>
     */
    private function present(array $f): array
    {
        $hours = round($f['hours'], 2);

        return [
            'hours' => $hours,
            'hourly_rate' => $hours > 0 ? $this->money($f['hourly'] / $f['hours']) : null,
            'hourly_amount' => $this->money($f['hourly']),
            'travel_hours' => round($f['travel_minutes'] / 60, 2),
            'gas' => $this->money($f['gas']),
            'money_owed' => $this->money($f['money_owed']),
            'fixed_amount' => $this->money($f['fixed']),
            'inspection' => $this->money($f['inspection']),
            'parts' => $this->money($f['parts']),
            'total' => $this->money($f['total']),
        ];
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
