<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ticket-level lifecycle: filtered indexes (store-scoped + global), creation
 * (with issue lines), soft-delete/restore, final note, derived status, and
 * presentation. The full filter set lives in applyFilters() (was TicketFilters).
 */
class TicketService
{
    /**
     * @var list<string>
     */
    private array $listWith = [
        'store',
        'ticketIssues.issue.creator',
        'ticketIssues.creator',
        'ticketIssues',
        'creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
    ];

    public function __construct(
        private TicketIssueService $issues,
        private CatalogService $catalog,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {
    }

    /**
     * Store-scoped index when $store is given, otherwise the global index.
     */
    public function index(Request $request, ?Store $store = null): LengthAwarePaginator
    {
        $query = $this->baseQuery($store)->with($this->listWith)->withCount('ticketIssues');

        $this->applyFilters($query, $request, $store);

        return $query->paginate($request->integer('per_page', 15))
            ->through(fn(Ticket $t) => $this->present($t));
    }

    /**
     * A ticket query scoped to $store when given. Shared by index() and
     * TicketAnalyticsService so store-scoping is defined in exactly one place.
     *
     * @return Builder<Ticket>
     */
    public function baseQuery(?Store $store = null): Builder
    {
        $query = Ticket::query();

        if ($store) {
            $query->where('store_id', $store->id);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $ticketFiles  Direct ticket attachments.
     * @param  array<int, array<int, UploadedFile>>  $issueFiles  Per-issue files, keyed by issue array index.
     * @return array<string, mixed>
     */
    public function create(Store $store, array $data, array $ticketFiles = [], array $issueFiles = []): array
    {
        $ticket = DB::transaction(function () use ($store, $data, $ticketFiles, $issueFiles) {
            $ticket = new Ticket([
                'store_id' => $store->id,
                'type'     => $data['type'] ?? TicketType::Normal->value,
            ]);
            $ticket->created_by = Auth::id();
            $ticket->save();

            foreach ($data['notes'] ?? [] as $noteData) {
                $this->notes->store($ticket, $noteData['body'], $noteData['type'] ?? null, []);
            }

            $this->attachments->store($ticket, $ticketFiles);

            foreach ($data['issues'] as $i => $line) {
                $issue = new TicketIssue([
                    'ticket_id'   => $ticket->id,
                    'issue_id'    => $line['issue_id'] ?? null,
                    'other_title' => $line['other_title'] ?? null,
                    'priority'    => $line['priority'],
                    'description' => $line['description'],
                    'status'      => IssueStatus::Pending->value,
                ]);
                $issue->created_by = Auth::id();
                $issue->save();

                foreach ($line['notes'] ?? [] as $noteData) {
                    $this->notes->store($issue, $noteData['body'], $noteData['type'] ?? null, []);
                }

                if (!empty($issueFiles[$i])) {
                    $this->attachments->store($issue, $issueFiles[$i]);
                }
            }

            return $ticket;
        });

        return $this->present($ticket->load($this->listWith)->loadCount('ticketIssues'));
    }

    /**
     * Create a ticket that is not tied to any store in the system.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $ticketFiles
     * @param  array<int, array<int, UploadedFile>>  $issueFiles
     * @return array<string, mixed>
     */
    public function createOther(array $data, array $ticketFiles = [], array $issueFiles = []): array
    {
        $ticket = DB::transaction(function () use ($data, $ticketFiles, $issueFiles) {
            $ticket = new Ticket([
                'other_store' => $data['other_store'],
                'type'        => $data['type'] ?? TicketType::Normal->value,
            ]);
            $ticket->created_by = Auth::id();
            $ticket->save();

            foreach ($data['notes'] ?? [] as $noteData) {
                $this->notes->store($ticket, $noteData['body'], $noteData['type'] ?? null, []);
            }

            $this->attachments->store($ticket, $ticketFiles);

            foreach ($data['issues'] as $i => $line) {
                $issue = new TicketIssue([
                    'ticket_id'   => $ticket->id,
                    'issue_id'    => $line['issue_id'] ?? null,
                    'other_title' => $line['other_title'] ?? null,
                    'priority'    => $line['priority'],
                    'description' => $line['description'],
                    'status'      => IssueStatus::Pending->value,
                ]);
                $issue->created_by = Auth::id();
                $issue->save();

                foreach ($line['notes'] ?? [] as $noteData) {
                    $this->notes->store($issue, $noteData['body'], $noteData['type'] ?? null, []);
                }

                if (!empty($issueFiles[$i])) {
                    $this->attachments->store($issue, $issueFiles[$i]);
                }
            }

            return $ticket;
        });

        return $this->present($ticket->load($this->listWith)->loadCount('ticketIssues'));
    }

    public function delete(Ticket $ticket): void
    {
        $ticket->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(Ticket $ticket): array
    {
        $ticket->restore();

        return $this->present($ticket->load($this->listWith)->loadCount('ticketIssues'));
    }

    /**
     * Re-load a ticket with the standard relations and present it. Used after a
     * side-effecting action (e.g. appending a final note) to return fresh data.
     *
     * @return array<string, mixed>
     */
    public function presentFresh(Ticket $ticket): array
    {
        return $this->present($ticket->load($this->listWith)->loadCount('ticketIssues'));
    }

    /**
     * Derive a ticket's status from its issues (no stored column).
     */
    public function deriveStatus(Ticket $ticket): TicketStatus
    {
        return TicketStatusService::for($ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Ticket $ticket): array
    {
        $status = $this->deriveStatus($ticket);
        $type   = $ticket->type ?? TicketType::Normal;

        return [
            'id'          => $ticket->id,
            'store_id'    => $ticket->store_id,
            'other_store' => $ticket->other_store,
            'type'        => ['value' => $type->value, 'label' => $type->label()],
            'store'       => $ticket->relationLoaded('store') && $ticket->store
                ? $this->presentStore($ticket->store)
                : null,
            'status'      => ['value' => $status->value, 'label' => $status->label()],
            'notes'       => $this->notes->presentMany($ticket),
            'attachments' => $this->attachments->presentMany($ticket),
            'issues'      => $ticket->relationLoaded('ticketIssues')
                ? $ticket->ticketIssues->map(fn(TicketIssue $i) => $this->issues->present($i))->all()
                : null,
            'issues_count' => $ticket->ticket_issues_count ?? null,
            'created_by'   => $ticket->created_by,
            'creator'      => $ticket->relationLoaded('creator') && $ticket->creator
                ? $this->catalog->presentUser($ticket->creator)
                : null,
            'created_at'  => $ticket->created_at,
            'updated_at'  => $ticket->updated_at,
            'deleted_at'  => $ticket->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStore(Store $store): array
    {
        return [
            'id'           => $store->id,
            'store_number' => $store->store_number,
            'notes'        => $this->notes->presentMany($store),
            'attachments'  => $this->attachments->presentMany($store),
            'created_at'   => $store->created_at,
            'updated_at'   => $store->updated_at,
        ];
    }

    // ------------------------------------------------------------- Filtering

    /**
     * The full composable filter set. Each clause is a no-op unless its query
     * parameter is present. Reads only query-string params. Public so
     * TicketAnalyticsService can scope its aggregates by the same filters.
     *
     * @param  Builder<Ticket>  $query
     */
    public function applyFilters(Builder $query, Request $request, ?Store $store = null): void
    {
        // ?stores[]=03795-00001 — global index only (store-scoped index is already scoped)
        if (!$store) {
            $storeNumbers = array_filter((array) $request->query('stores', []));
            if (!empty($storeNumbers)) {
                $query->whereHas('store', fn(Builder $q) => $q->whereIn('store_number', $storeNumbers));
            }
        }

        // ?statuses[]=pending&statuses[]=assigned (OR logic)
        $statusValues = array_filter((array) $request->query('statuses', []));
        $statuses = array_values(array_filter(
            array_map(fn($v) => TicketStatus::tryFrom((string) $v), $statusValues)
        ));
        if (!empty($statuses)) {
            $this->filterByDerivedStatuses($query, $statuses);
        }

        // ?issue_ids[]=1&issue_ids[]=2
        $issueIds = array_filter((array) $request->query('issue_ids', []));
        if (!empty($issueIds)) {
            $query->whereHas('ticketIssues', fn(Builder $q) => $q->whereIn('issue_id', $issueIds));
        }

        // ?issue_statuses[]=pending&issue_statuses[]=assigned
        $issueStatuses = array_filter((array) $request->query('issue_statuses', []));
        if (!empty($issueStatuses)) {
            $query->whereHas('ticketIssues', fn(Builder $q) => $q->whereIn('status', $issueStatuses));
        }

        // ?priorities[]=urgent&priorities[]=high
        $priorities = array_filter((array) $request->query('priorities', []));
        if (!empty($priorities)) {
            $query->whereHas('ticketIssues', fn(Builder $q) => $q->whereIn('priority', $priorities));
        }

        // ?types[]=normal&types[]=preventive_maintenance
        $types = array_filter((array) $request->query('types', []));
        if (!empty($types)) {
            $query->whereIn('type', $types);
        }

        if ($from = $request->query('created_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('created_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // ?changed_statuses[]=assigned&changed_statuses[]=complete&changed_statuses[]=waiting
        // &changed_from=2026-01-01&changed_to=2026-01-31
        // Tickets with an issue that changed TO any of these statuses within the date range.
        $changedStatusValues = array_filter((array) $request->query('changed_statuses', []));
        $changedFrom = $request->query('changed_from');
        $changedTo   = $request->query('changed_to');
        if (!empty($changedStatusValues) || $changedFrom || $changedTo) {
            $changedStatuses = array_values(array_filter(
                array_map(fn($v) => IssueStatus::tryFrom((string) $v), $changedStatusValues)
            ));

            $query->whereHas('ticketIssues.statusChanges', function (Builder $q) use ($changedStatuses, $changedFrom, $changedTo) {
                if (!empty($changedStatuses)) {
                    $q->whereIn('to_status', array_map(fn(IssueStatus $s) => $s->value, $changedStatuses));
                }
                if ($changedFrom) {
                    $q->whereDate('created_at', '>=', $changedFrom);
                }
                if ($changedTo) {
                    $q->whereDate('created_at', '<=', $changedTo);
                }
            });
        }

        // At least one issue whose summed (non-mistaken) part cost exceeds N.
        if (($single = $request->query('part_cost_single_gt')) !== null && $single !== '') {
            $query->whereHas('ticketIssues', function (Builder $q) use ($single) {
                $q->whereIn('ticket_issues.id', function (QueryBuilder $sub) use ($single) {
                    $sub->from('part_ticket_issue as pti')
                        ->join('part_usages as pu', 'pu.id', '=', 'pti.part_usage_id')
                        ->where('pu.mistaken', false)
                        ->select('pti.ticket_issue_id')
                        ->groupBy('pti.ticket_issue_id')
                        ->havingRaw('SUM(pu.cost) > ?', [$single]);
                });
            });
        }

        // Whole-ticket (non-mistaken) part cost exceeds N; a shared part counts once.
        if (($total = $request->query('part_cost_total_gt')) !== null && $total !== '') {
            $distinct = DB::table('ticket_issues as ti')
                ->join('part_ticket_issue as pti', 'pti.ticket_issue_id', '=', 'ti.id')
                ->join('part_usages as pu', 'pu.id', '=', 'pti.part_usage_id')
                ->where('pu.mistaken', false)
                ->distinct()
                ->select('ti.ticket_id', 'pu.id as pu_id', 'pu.cost');

            $query->whereIn('tickets.id', function (QueryBuilder $sub) use ($distinct, $total) {
                $sub->fromSub($distinct, 'd')
                    ->select('d.ticket_id')
                    ->groupBy('d.ticket_id')
                    ->havingRaw('SUM(d.cost) > ?', [$total]);
            });
        }

        // ?technician_ids[]=1&technician_ids[]=2
        $technicianIds = array_filter((array) $request->query('technician_ids', []));
        if (!empty($technicianIds)) {
            $query->whereHas('ticketIssues.technicians', fn(Builder $q) => $q->whereIn('technicians.id', $technicianIds));
        }

        // ?creator_ids[]=1&creator_ids[]=2
        $creatorIds = array_filter((array) $request->query('creator_ids', []));
        if (!empty($creatorIds)) {
            $query->whereIn('created_by', $creatorIds);
        }

        match ($request->query('trashed')) {
            'with'  => $query->withTrashed(),
            'only'  => $query->onlyTrashed(),
            default => null,
        };

        $sort = in_array($request->query('sort'), ['created_at', 'updated_at', 'id'], true)
            ? $request->query('sort')
            : 'created_at';
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);
    }

    /**
     * Filter by one or more derived ticket statuses (OR logic).
     *
     * @param  Builder<Ticket>     $query
     * @param  list<TicketStatus>  $statuses
     */
    private function filterByDerivedStatuses(Builder $query, array $statuses): void
    {
        $query->where(function (Builder $outer) use ($statuses) {
            foreach ($statuses as $i => $status) {
                $fn = fn(Builder $q) => $this->applyDerivedStatusClause($q, $status);
                $i === 0 ? $outer->where($fn) : $outer->orWhere($fn);
            }
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyDerivedStatusClause(Builder $query, TicketStatus $status): void
    {
        $hasStatus = fn(IssueStatus $s) => fn(Builder $q) => $q->where('status', $s->value);

        match ($status) {
            TicketStatus::Waiting => $query->whereHas('ticketIssues', $hasStatus(IssueStatus::Waiting)),

            TicketStatus::InProgress => $query
                ->whereHas('ticketIssues', $hasStatus(IssueStatus::InProgress))
                ->whereDoesntHave('ticketIssues', $hasStatus(IssueStatus::Waiting)),

            TicketStatus::Assigned => $query
                ->whereHas('ticketIssues', $hasStatus(IssueStatus::Assigned))
                ->whereDoesntHave('ticketIssues', $hasStatus(IssueStatus::InProgress))
                ->whereDoesntHave('ticketIssues', $hasStatus(IssueStatus::Waiting)),

            TicketStatus::Complete => $query
                ->whereHas('ticketIssues')
                ->whereDoesntHave('ticketIssues', fn(Builder $q) => $q->whereNotIn('status', [
                    IssueStatus::Complete->value,
                    IssueStatus::Deferred->value,
                    IssueStatus::Cancelled->value,
                ]))
                ->whereHas('ticketIssues', fn(Builder $q) => $q->whereIn('status', [
                    IssueStatus::Complete->value,
                    IssueStatus::Deferred->value,
                ])),

            TicketStatus::Cancelled => $query
                ->whereHas('ticketIssues')
                ->whereDoesntHave('ticketIssues', fn(Builder $q) => $q->where('status', '!=', IssueStatus::Cancelled->value)),

            TicketStatus::Pending => $query
                ->whereDoesntHave('ticketIssues', $hasStatus(IssueStatus::InProgress))
                ->whereDoesntHave('ticketIssues', $hasStatus(IssueStatus::Assigned))
                ->where(fn(Builder $q) => $q
                    ->whereDoesntHave('ticketIssues')
                    ->orWhereHas('ticketIssues', $hasStatus(IssueStatus::Pending))),
        };
    }
}
