<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate metrics over the same filtered ticket set the index endpoints
 * list — issue status breakdown, time-in-status durations, and a Tue-Mon
 * weekly ticket-creation average. Reuses TicketService::applyFilters() so
 * "which tickets are in scope" is defined in exactly one place.
 */
class TicketAnalyticsService
{
    public function __construct(private TicketService $tickets)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(Request $request, ?Store $store = null): array
    {
        return [
            'issues' => $this->issueBreakdown($request, $store),
            'durations' => [
                'pending_to_next_status' => $this->averageDuration($request, $store, null),
                'time_to_complete_or_cancelled' => $this->averageDuration(
                    $request,
                    $store,
                    [IssueStatus::Complete->value, IssueStatus::Cancelled->value]
                ),
            ],
            'avg_tickets_per_week' => $this->weeklyAverage($request, $store),
        ];
    }

    /**
     * A ticket-id subquery scoped by the full active filter set. Passed
     * directly into whereIn(...) — Laravel turns an Eloquent/Query Builder
     * given to whereIn into a correlated `IN (SELECT ...)`.
     *
     * @return Builder<\App\Models\Ticket>
     */
    private function scopedTicketIdsQuery(Request $request, ?Store $store): Builder
    {
        $query = $this->tickets->baseQuery($store)->select('tickets.id');
        $this->tickets->applyFilters($query, $request, $store);

        return $query->reorder();
    }

    /**
     * Issue counts per IssueStatus (all 7 values always present, zero-filled)
     * plus a total. total = "how many issues" match the active filters
     * (i.e. "new" when scoped via created_from/changed_from etc.);
     * status_breakdown[complete] = "how many completed".
     *
     * @return array<string, mixed>
     */
    private function issueBreakdown(Request $request, ?Store $store): array
    {
        $counts = DB::table('ticket_issues')
            ->whereIn('ticket_id', $this->scopedTicketIdsQuery($request, $store))
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $breakdown = array_map(fn(IssueStatus $s) => [
            'status' => $s->value,
            'label'  => $s->label(),
            'count'  => (int) ($counts[$s->value] ?? 0),
        ], IssueStatus::cases());

        return [
            'total' => array_sum(array_column($breakdown, 'count')),
            'status_breakdown' => $breakdown,
        ];
    }

    /**
     * Average seconds/hours from an issue's creation to the first status
     * change matching $toStatuses (or any status change at all when null).
     * MIN(created_at) grouped by ticket_issue_id gives first-passage
     * semantics for free: a later reopen's to_status won't match the filter,
     * so the original arrival time is still what MIN() picks up.
     *
     * @param  list<string>|null  $toStatuses
     * @return array<string, mixed>
     */
    private function averageDuration(Request $request, ?Store $store, ?array $toStatuses): array
    {
        $firstChange = DB::table('issue_status_changes')
            ->select('ticket_issue_id', DB::raw('MIN(created_at) as changed_at'))
            ->when($toStatuses, fn($q) => $q->whereIn('to_status', $toStatuses))
            ->groupBy('ticket_issue_id');

        $rows = DB::table('ticket_issues as ti')
            ->joinSub($firstChange, 'fc', 'fc.ticket_issue_id', '=', 'ti.id')
            ->whereIn('ti.ticket_id', $this->scopedTicketIdsQuery($request, $store))
            ->select('ti.created_at as started_at', 'fc.changed_at')
            ->get();

        if ($rows->isEmpty()) {
            return ['avg_seconds' => null, 'avg_hours' => null, 'sample_size' => 0];
        }

        $totalSeconds = $rows->sum(
            fn($r) => abs(Carbon::parse($r->changed_at)->diffInSeconds(Carbon::parse($r->started_at)))
        );
        $avgSeconds = $totalSeconds / $rows->count();

        return [
            'avg_seconds' => round($avgSeconds, 2),
            'avg_hours'   => round($avgSeconds / 3600, 2),
            'sample_size' => $rows->count(),
        ];
    }

    /**
     * Average tickets filed per custom week (Tue 00:00:00 - Mon 23:59:59),
     * always over full history: date-bound filters (created_from/to,
     * changed_from/to) are stripped, every other active filter still
     * applies. weeks_spanned counts every week between the earliest and
     * latest matching ticket inclusive, so weeks with zero tickets still
     * count toward the denominator.
     *
     * @return array<string, mixed>
     */
    private function weeklyAverage(Request $request, ?Store $store): array
    {
        $query = $this->tickets->baseQuery($store);
        $this->tickets->applyFilters($query, $this->withoutDateFilters($request), $store);

        $row = $query->reorder()->toBase()
            ->selectRaw('COUNT(*) as total, MIN(created_at) as earliest, MAX(created_at) as latest')
            ->first();

        if (!$row || (int) $row->total === 0) {
            return [
                'value' => null,
                'total_tickets' => 0,
                'weeks_spanned' => null,
                'span_start' => null,
                'span_end' => null,
                'week_starts_on' => 'tuesday',
            ];
        }

        $firstWeek = Carbon::parse($row->earliest)->startOfWeek(Carbon::TUESDAY);
        $lastWeek  = Carbon::parse($row->latest)->startOfWeek(Carbon::TUESDAY);
        $weeksSpanned = (int) round(abs($firstWeek->diffInWeeks($lastWeek))) + 1;

        return [
            'value' => round($row->total / $weeksSpanned, 4),
            'total_tickets' => (int) $row->total,
            'weeks_spanned' => $weeksSpanned,
            'span_start' => $firstWeek->toDateString(),
            'span_end' => $lastWeek->copy()->addDays(6)->toDateString(),
            'week_starts_on' => 'tuesday',
        ];
    }

    /**
     * A clone of $request with the date-bound filter keys removed, so the
     * weekly average can stay "all-time" without mutating the caller's
     * request or affecting any other metric computed from it.
     */
    private function withoutDateFilters(Request $request): Request
    {
        $query = $request->query();
        unset($query['created_from'], $query['created_to'], $query['changed_from'], $query['changed_to']);

        return $request->duplicate($query);
    }
}
