<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Enums\TicketStatus;
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
        $query = Ticket::query()->with($this->listWith)->withCount('ticketIssues');

        if ($store) {
            $query->where('store_id', $store->id);
        }

        $this->applyFilters($query, $request);

        return $query->paginate($request->integer('per_page', 15))
            ->through(fn(Ticket $t) => $this->present($t));
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
            $ticket = new Ticket(['store_id' => $store->id]);
            $ticket->created_by = Auth::id();
            $ticket->save();

            // Ticket-level notes (text-only at creation; attach files via POST .../notes afterward).
            foreach ($data['notes'] ?? [] as $noteData) {
                $this->notes->store($ticket, $noteData['body'], $noteData['type'] ?? null, []);
            }

            // Ticket-level direct file attachments.
            $this->attachments->store($ticket, $ticketFiles);

            foreach ($data['issues'] as $i => $line) {
                $issue = new TicketIssue([
                    'ticket_id' => $ticket->id,
                    'issue_id' => $line['issue_id'] ?? null,
                    'other_title' => $line['other_title'] ?? null,
                    'priority' => $line['priority'],
                    'description' => $line['description'],
                    'status' => IssueStatus::Pending->value,
                ]);
                $issue->created_by = Auth::id();
                $issue->save();

                // Per-issue notes.
                foreach ($line['notes'] ?? [] as $noteData) {
                    $this->notes->store($issue, $noteData['body'], $noteData['type'] ?? null, []);
                }

                // Per-issue direct file attachments.
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

        return [
            'id' => $ticket->id,
            'store_id' => $ticket->store_id,
            'store' => $ticket->relationLoaded('store') && $ticket->store
                ? $this->presentStore($ticket->store)
                : null,
            'status' => ['value' => $status->value, 'label' => $status->label()],
            'notes' => $this->notes->presentMany($ticket),
            'attachments' => $this->attachments->presentMany($ticket),
            'issues' => $ticket->relationLoaded('ticketIssues')
                ? $ticket->ticketIssues->map(fn(TicketIssue $i) => $this->issues->present($i))->all()
                : null,
            'issues_count' => $ticket->ticket_issues_count ?? null,
            'created_by' => $ticket->created_by,
            'creator' => $ticket->relationLoaded('creator') && $ticket->creator
                ? $this->catalog->presentUser($ticket->creator)
                : null,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'deleted_at' => $ticket->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStore(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_number' => $store->store_number,
            'notes' => $this->notes->presentMany($store),
            'attachments' => $this->attachments->presentMany($store),
            'created_at' => $store->created_at,
            'updated_at' => $store->updated_at,
        ];
    }

    // ------------------------------------------------------------- Filtering

    /**
     * The full composable filter set (was App\Filters). Each clause is a no-op
     * unless its query parameter is present. Reads only query-string params.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // ?store=03795-00001 (mainly for the global index)
        if ($store = $request->query('store')) {
            $query->whereHas('store', fn(Builder $q) => $q->where('store_number', $store));
        }

        // ?status= derived ticket status (mirrors TicketStatusService precedence)
        if ($status = TicketStatus::tryFrom((string) $request->query('status'))) {
            $this->filterByDerivedStatus($query, $status);
        }

        if ($issueId = $request->query('issue_id')) {
            $query->whereHas('ticketIssues', fn(Builder $q) => $q->where('issue_id', $issueId));
        }

        if ($issueStatus = $request->query('issue_status')) {
            $query->whereHas('ticketIssues', fn(Builder $q) => $q->where('status', $issueStatus));
        }

        if ($priority = $request->query('priority')) {
            $query->whereHas('ticketIssues', fn(Builder $q) => $q->where('priority', $priority));
        }

        if ($from = $request->query('created_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('created_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $assignedFrom = $request->query('assigned_from');
        $assignedTo = $request->query('assigned_to');
        if ($assignedFrom || $assignedTo) {
            $query->whereHas('ticketIssues.assignments', function (Builder $q) use ($assignedFrom, $assignedTo) {
                if ($assignedFrom) {
                    $q->whereDate('assigned_date', '>=', $assignedFrom);
                }
                if ($assignedTo) {
                    $q->whereDate('assigned_date', '<=', $assignedTo);
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

        if ($technicianId = $request->query('technician_id')) {
            $query->whereHas('ticketIssues.technicians', fn(Builder $q) => $q->where('technicians.id', $technicianId));
        }

        if ($creator = $request->query('creator_id', $request->query('created_by'))) {
            $query->where('created_by', $creator);
        }

        match ($request->query('trashed')) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };

        $sort = in_array($request->query('sort'), ['created_at', 'updated_at', 'id'], true)
            ? $request->query('sort')
            : 'created_at';
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function filterByDerivedStatus(Builder $query, TicketStatus $status): void
    {
        $hasStatus = fn(IssueStatus $s) => fn(Builder $q) => $q->where('status', $s->value);

        match ($status) {
            TicketStatus::InProgress => $query->whereHas('ticketIssues', $hasStatus(IssueStatus::InProgress)),

            TicketStatus::Assigned => $query
                ->whereHas('ticketIssues', $hasStatus(IssueStatus::Assigned))
                ->whereDoesntHave('ticketIssues', $hasStatus(IssueStatus::InProgress)),

                // All issues finished (complete/deferred/cancelled) AND at least one
                // is not a cancellation — an all-cancelled ticket is Cancelled, below.
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

                // Every issue cancelled.
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
