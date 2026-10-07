<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Attachment;
use App\Models\IssueStatusChange;
use App\Models\Note;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The maintenance analytics page -- "a manager's one-stop look": what was
 * filed, what changed, what keeps coming back, what nobody has touched.
 *
 * Three sections, each answered for a set of stores:
 *   summary    figures and lists for the chosen range (default: yesterday)
 *   activity   the tickets that changed in the range, and how
 *   watchlist  the current state: untouched tickets and recurring issues
 *
 * "Changed" is read straight off updated_at: issues, records, notes and files
 * touch their ticket (see the models' $touches). The range arrives as two
 * instants (the viewer's local midnights, in UTC), so every comparison here is
 * on timestamps, never whereDate().
 */
class MaintenanceAnalyticsService
{
    /** A ticket with an open issue and no change for this many days is "untouched". */
    public const UNTOUCHED_DAYS = 1;

    /** The same catalog issue at the same store on this many tickets ... */
    public const RECURRING_MIN = 3;

    /** ... within this many days is "recurring". */
    public const RECURRING_DAYS = 90;

    public const MAX_RANGE_DAYS = 92;

    /** @var array<int, string> */
    private array $open;

    public function __construct(private NoteService $notes)
    {
        $this->open = array_values(array_map(
            fn (IssueStatus $s) => $s->value,
            array_filter(IssueStatus::cases(), fn (IssueStatus $s) => !in_array($s, IssueStatus::terminal(), true))
        ));
    }

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, Store>
     */
    public function stores(array $codes): Collection
    {
        return Store::query()->whereIn('store_number', $codes)->orderBy('store_number')->get();
    }

    // ------------------------------------------------------------------ Summary

    /**
     * @param  Collection<int, Store>  $stores
     * @return array<string, mixed>
     */
    public function summary(Collection $stores, Carbon $from, Carbon $to, int $recurringMin, int $recurringDays, int $untouchedDays): array
    {
        $storeIds = $stores->pluck('id')->all();

        $created = Ticket::query()
            ->whereIn('store_id', $storeIds)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->with(['ticketIssues.issue', 'creator', 'store'])
            ->orderByDesc('created_at')
            ->get();

        $issuesCreated = $created->flatMap(fn (Ticket $t) => $t->ticketIssues->whereNull('parent_id'));

        $completions = $this->completionsIn($storeIds, $from, $to);
        $recurring = $this->recurringMap($storeIds, $to, $recurringDays, $recurringMin);

        return [
            'range' => ['from' => $from, 'to' => $to],
            'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'store_number' => $s->store_number])->values()->all(),
            'kpis' => [
                'tickets_created' => $created->count(),
                'issues_created' => $issuesCreated->count(),
                'issues_completed' => $completions->count(),
                'open_tickets' => $this->openTicketsQuery($storeIds)->count(),
                'untouched_tickets' => $this->untouchedQuery($storeIds, $untouchedDays)->count(),
                'recurring_issues' => count($recurring),
                'avg_hours_to_complete' => $completions->isEmpty() ? null : round($completions->avg('hours'), 1),
                'changed_tickets' => $this->changedQuery($storeIds, $from, $to)->count(),
            ],
            'created' => $created->map(fn (Ticket $t) => [
                'ticket_id' => $t->id,
                'store_number' => $t->store?->store_number,
                'created_at' => $t->created_at,
                'creator' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name] : null,
                'status' => $this->status($t),
                'issues' => $t->ticketIssues->whereNull('parent_id')->map(function (TicketIssue $i) use ($recurring, $t) {
                    $key = "{$t->store_id}:{$i->issue_id}";

                    return [
                        'id' => $i->id,
                        'issue_id' => $i->issue_id,
                        'title' => $i->displayTitle(),
                        'priority' => ['value' => $i->priority->value, 'label' => $i->priority->label()],
                        'status' => ['value' => $i->status->value, 'label' => $i->status->label()],
                        // "This store's 4th Oven ticket in 90 days."
                        'recurring_count' => $i->issue_id !== null && isset($recurring[$key]) ? $recurring[$key]['count'] : null,
                    ];
                })->values()->all(),
            ])->values()->all(),
            'by_issue' => $issuesCreated
                ->groupBy(fn (TicketIssue $i) => $i->issue_id ? "issue:{$i->issue_id}" : 'other')
                ->map(fn (Collection $g, string $key) => [
                    'issue_id' => $key === 'other' ? null : $g->first()->issue_id,
                    'title' => $key === 'other' ? 'Other (not in the catalog)' : $g->first()->displayTitle(),
                    'count' => $g->count(),
                ])
                ->sortByDesc('count')->values()->all(),
            'by_store' => $stores->map(fn (Store $s) => [
                'store_number' => $s->store_number,
                'created' => $created->where('store_id', $s->id)->count(),
                'completed' => $completions->where('store_id', $s->id)->count(),
                'open' => $this->openTicketsQuery([$s->id])->count(),
            ])->values()->all(),
            'by_status' => $this->openIssueStatuses($storeIds),
            'completion_by_issue' => $completions
                ->groupBy(fn ($c) => $c['issue_id'] ? "issue:{$c['issue_id']}" : 'other')
                ->map(fn (Collection $g) => [
                    'issue_id' => $g->first()['issue_id'],
                    'title' => $g->first()['title'],
                    'completed' => $g->count(),
                    'avg_hours' => round($g->avg('hours'), 1),
                ])
                ->sortByDesc('completed')->values()->all(),
            'recurring_window' => ['min' => $recurringMin, 'days' => $recurringDays],
            'untouched_days' => $untouchedDays,
        ];
    }

    // ----------------------------------------------------------------- Activity

    /**
     * The tickets that changed in the range, most recently changed first, each
     * with what changed.
     *
     * @param  Collection<int, Store>  $stores
     */
    public function activity(Collection $stores, Carbon $from, Carbon $to, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->changedQuery($stores->pluck('id')->all(), $from, $to)
            ->with(['ticketIssues.issue', 'store'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Ticket $ticket) => [
                'ticket_id' => $ticket->id,
                'store_number' => $ticket->store?->store_number,
                'title' => $this->titles($ticket),
                'status' => $this->status($ticket),
                'updated_at' => $ticket->updated_at,
                'changes' => $this->changes($ticket, $from, $to),
            ]);
    }

    /**
     * What changed on a ticket between two instants, from the tables that
     * already record it: status changes (with who and when), whether it was
     * opened then, and how many notes and files were added. Public notes only
     * -- a private one is never counted, nor its files.
     *
     * @return array{opened: bool, status_changes: array<int, array<string, mixed>>, notes: int, files: int}
     */
    public function changes(Ticket $ticket, Carbon $since, ?Carbon $until = null): array
    {
        $between = function ($q) use ($since, $until) {
            $q->where('created_at', '>', $since);
            if ($until !== null) {
                $q->where('created_at', '<', $until);
            }
        };

        $statusChanges = IssueStatusChange::query()
            ->whereHas('ticketIssue', fn ($q) => $q->where('ticket_id', $ticket->id))
            ->where($between)
            ->where(fn ($q) => $q->whereNull('from_status')->orWhereColumn('from_status', '!=', 'to_status'))
            ->with(['ticketIssue.issue', 'creator'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (IssueStatusChange $c) => [
                'title' => $c->ticketIssue?->displayTitle(),
                'from' => $c->from_status?->label(),
                'to' => $c->to_status->label(),
                'by' => $c->creator?->name,
                'at' => $c->created_at,
            ])
            ->values()
            ->all();

        $holders = $this->notes->ticketHolders($ticket);
        $publicNoteIds = $this->held(Note::query(), 'notable', $holders)->where('is_private', false)->pluck('id')->all();

        $notes = $this->held(Note::query(), 'notable', $holders)->where('is_private', false)->where($between)->count();
        $files = $this->held(Attachment::query(), 'attachable', $holders + [Note::class => $publicNoteIds])->where($between)->count();

        $opened = $ticket->created_at !== null
            && $ticket->created_at->greaterThan($since)
            && ($until === null || $ticket->created_at->lessThan($until));

        return ['opened' => $opened, 'status_changes' => $statusChanges, 'notes' => $notes, 'files' => $files];
    }

    // ---------------------------------------------------------------- Watchlist

    /**
     * @param  Collection<int, Store>  $stores
     * @return array<string, mixed>
     */
    public function watchlist(Collection $stores, Carbon $to, int $recurringMin, int $recurringDays, int $untouchedDays): array
    {
        $storeIds = $stores->pluck('id')->all();
        $codes = $stores->pluck('store_number', 'id');

        $recurring = collect($this->recurringMap($storeIds, $to, $recurringDays, $recurringMin))
            ->map(fn (array $r) => $r + ['store_number' => $codes[$r['store_id']] ?? null])
            ->sortByDesc('count')
            ->values()
            ->all();

        $untouched = $this->untouchedQuery($storeIds, $untouchedDays)
            ->with(['ticketIssues.issue', 'store'])
            ->withMax('ticketIssues as issues_updated_at', 'updated_at')
            ->get()
            ->map(function (Ticket $t) {
                $issues = $t->getAttributes()['issues_updated_at'] ?? null;
                $last = $issues !== null && Carbon::parse($issues)->greaterThan($t->updated_at) ? Carbon::parse($issues) : $t->updated_at;

                return [
                    'ticket_id' => $t->id,
                    'store_number' => $t->store?->store_number,
                    'created_at' => $t->created_at,
                    'last_change_at' => $last,
                    'days_silent' => (int) floor($last->diffInHours(now()) / 24),
                    'status' => $this->status($t),
                    'open_issues' => $t->ticketIssues
                        ->filter(fn (TicketIssue $i) => in_array($i->status->value, $this->open, true))
                        ->map(fn (TicketIssue $i) => [
                            'id' => $i->id,
                            'issue_id' => $i->issue_id,
                            'title' => $i->displayTitle(),
                            'status' => ['value' => $i->status->value, 'label' => $i->status->label()],
                        ])->values()->all(),
                ];
            })
            ->sortBy(fn (array $row) => $row['last_change_at']->getTimestamp())
            ->values()
            ->all();

        return [
            'untouched' => $untouched,
            'recurring' => $recurring,
            'untouched_days' => $untouchedDays,
            'recurring_window' => ['min' => $recurringMin, 'days' => $recurringDays],
        ];
    }

    // ------------------------------------------------------------------ Pieces

    /**
     * Tickets with at least one issue still open (not complete, cancelled or
     * deferred), not archived.
     *
     * @param  array<int, int>  $storeIds
     * @return Builder<Ticket>
     */
    private function openTicketsQuery(array $storeIds): Builder
    {
        return Ticket::query()
            ->whereIn('store_id', $storeIds)
            ->whereHas('ticketIssues', fn ($q) => $q->whereIn('status', $this->open));
    }

    /**
     * Open tickets with no change -- to the ticket or any of its issues -- for
     * `$days` days or more.
     *
     * @param  array<int, int>  $storeIds
     * @return Builder<Ticket>
     */
    private function untouchedQuery(array $storeIds, int $days): Builder
    {
        $cutoff = now()->subDays($days);

        return $this->openTicketsQuery($storeIds)
            ->where('updated_at', '<=', $cutoff)
            ->whereDoesntHave('ticketIssues', fn ($q) => $q->where('updated_at', '>', $cutoff));
    }

    /**
     * Tickets where the ticket or one of its issues changed in the range.
     *
     * @param  array<int, int>  $storeIds
     * @return Builder<Ticket>
     */
    private function changedQuery(array $storeIds, Carbon $from, Carbon $to): Builder
    {
        $inRange = fn ($q) => $q->where('updated_at', '>=', $from)->where('updated_at', '<', $to);

        return Ticket::query()
            ->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->where($inRange)->orWhereHas('ticketIssues', $inRange));
    }

    /**
     * Rows of a morph-owned table (notes, attachments) held by any of these.
     *
     * @param  array<class-string, array<int, int>>  $holders
     */
    private function held(Builder $query, string $morph, array $holders): Builder
    {
        return $query->where(function ($q) use ($morph, $holders) {
            $q->whereRaw('1 = 0');
            foreach ($holders as $class => $ids) {
                if ($ids !== []) {
                    $q->orWhere(fn ($w) => $w->where("{$morph}_type", $class)->whereIn("{$morph}_id", $ids));
                }
            }
        });
    }

    /**
     * Catalog issues that keep coming back: (store, issue) pairs reported on at
     * least `$min` different tickets in the `$days` days up to `$to`. Deferral
     * follow-ups are the same report, so only root issues count.
     *
     * @param  array<int, int>  $storeIds
     * @return array<string, array<string, mixed>>  keyed "{store_id}:{issue_id}"
     */
    private function recurringMap(array $storeIds, Carbon $to, int $days, int $min): array
    {
        $rows = DB::table('ticket_issues')
            ->join('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->leftJoin('issues', 'issues.id', '=', 'ticket_issues.issue_id')
            ->whereIn('tickets.store_id', $storeIds)
            ->whereNull('tickets.deleted_at')
            ->whereNull('ticket_issues.parent_id')
            ->whereNotNull('ticket_issues.issue_id')
            ->where('ticket_issues.created_at', '>=', $to->copy()->subDays($days))
            ->where('ticket_issues.created_at', '<', $to)
            ->groupBy('tickets.store_id', 'ticket_issues.issue_id', 'issues.title')
            ->havingRaw('COUNT(DISTINCT ticket_issues.ticket_id) >= ?', [$min])
            ->select(
                'tickets.store_id',
                'ticket_issues.issue_id',
                'issues.title',
                DB::raw('COUNT(DISTINCT ticket_issues.ticket_id) as ticket_count'),
                DB::raw('MAX(ticket_issues.created_at) as last_at'),
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out["{$r->store_id}:{$r->issue_id}"] = [
                'store_id' => (int) $r->store_id,
                'issue_id' => (int) $r->issue_id,
                'title' => $r->title ?? "Issue #{$r->issue_id}",
                'count' => (int) $r->ticket_count,
                'last_at' => $r->last_at ? Carbon::parse($r->last_at) : null,
            ];
        }

        return $out;
    }

    /**
     * Issues that reached Complete inside the range, with how long each took
     * from being reported. First completion per issue in the range.
     *
     * @param  array<int, int>  $storeIds
     * @return Collection<int, array{issue_id: ?int, title: string, store_id: int, hours: float}>
     */
    private function completionsIn(array $storeIds, Carbon $from, Carbon $to): Collection
    {
        return DB::table('issue_status_changes')
            ->join('ticket_issues', 'ticket_issues.id', '=', 'issue_status_changes.ticket_issue_id')
            ->join('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->leftJoin('issues', 'issues.id', '=', 'ticket_issues.issue_id')
            ->whereIn('tickets.store_id', $storeIds)
            ->whereNull('tickets.deleted_at')
            ->where('issue_status_changes.to_status', IssueStatus::Complete->value)
            ->where('issue_status_changes.created_at', '>=', $from)
            ->where('issue_status_changes.created_at', '<', $to)
            ->orderBy('issue_status_changes.created_at')
            ->get([
                'ticket_issues.id as ticket_issue_id',
                'ticket_issues.issue_id',
                'ticket_issues.other_title',
                'ticket_issues.created_at as reported_at',
                'issues.title',
                'tickets.store_id',
                'issue_status_changes.created_at as completed_at',
            ])
            ->unique('ticket_issue_id')
            ->map(fn ($r) => [
                'issue_id' => $r->issue_id === null ? null : (int) $r->issue_id,
                'title' => $r->title ?? ($r->other_title ?: 'Other (not in the catalog)'),
                'store_id' => (int) $r->store_id,
                'hours' => round(Carbon::parse($r->reported_at)->diffInMinutes(Carbon::parse($r->completed_at)) / 60, 2),
            ])
            ->values();
    }

    /**
     * The open workload by status, now.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array<string, mixed>>
     */
    private function openIssueStatuses(array $storeIds): array
    {
        $counts = DB::table('ticket_issues')
            ->join('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->whereIn('tickets.store_id', $storeIds)
            ->whereNull('tickets.deleted_at')
            ->whereIn('ticket_issues.status', $this->open)
            ->groupBy('ticket_issues.status')
            ->select('ticket_issues.status', DB::raw('COUNT(*) as n'))
            ->get()
            ->pluck('n', 'status');

        return collect($this->open)->map(fn (string $status) => [
            'status' => $status,
            'label' => IssueStatus::from($status)->label(),
            'count' => (int) ($counts[$status] ?? 0),
        ])->values()->all();
    }

    /** @return array{value: string, label: string} */
    private function status(Ticket $ticket): array
    {
        $status = TicketStatusService::for($ticket);

        return ['value' => $status->value, 'label' => $status->label()];
    }

    private function titles(Ticket $ticket): string
    {
        return $ticket->ticketIssues->whereNull('parent_id')
            ->map(fn (TicketIssue $i) => $i->displayTitle())
            ->filter()->unique()->implode(', ');
    }
}
