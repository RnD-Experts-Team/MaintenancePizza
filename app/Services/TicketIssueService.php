<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueStatusChange;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\Warranty;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issue-level reads and the few direct edits left after v2 moved the workflow
 * into its own actions (docs/MAINTENANCE-V2.md §2): the full read (presented
 * by TicketIssuePresenter), re-linking, the assigned priority, cancelling,
 * the store history of a catalog issue, and the checks that records belong
 * to a ticket.
 */
class TicketIssueService
{
    public function __construct(
        private CatalogService $catalog,
        private TicketIssuePresenter $presenter,
        private IssueWorkflowService $workflow,
    ) {
    }

    /**
     * Every issue of a ticket, each with its full read (one eager load for
     * all of them).
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(Ticket $ticket): array
    {
        return $ticket->ticketIssues()->orderBy('id')->with(TicketIssuePresenter::with())->get()
            ->map(fn (TicketIssue $i) => $this->present($i))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(TicketIssue $ticketIssue): array
    {
        $fresh = TicketIssue::query()->whereKey($ticketIssue->id)->with(TicketIssuePresenter::with())->firstOrFail();

        return $this->present($fresh);
    }

    /**
     * The body every workflow action answers with: the issue's full read,
     * plus `trip_link` when the action created a trip -- its plain token is
     * shown this once and never again (only its hash is stored), so the
     * dashboard must pass it on to the technician now.
     *
     * @return array{data: array<string, mixed>, trip_link?: array{url_token: string, expires_after_hours: int, trip_id: int}}
     */
    public function respond(TicketIssue $issue, ?string $token = null, ?Trip $trip = null): array
    {
        $body = ['data' => $this->show($issue)];

        if ($token !== null && $trip !== null) {
            $body['trip_link'] = [
                'url_token' => $token,
                'expires_after_hours' => (int) $trip->link_idle_hours,
                'trip_id' => $trip->id,
            ];
        }

        return $body;
    }

    /**
     * Re-link an issue to a different catalog issue.
     *
     * @return array<string, mixed>
     */
    public function update(TicketIssue $ticketIssue, int $issueId): array
    {
        $ticketIssue->issue_id = $issueId;
        $ticketIssue->save();

        return $this->show($ticketIssue);
    }

    /**
     * Set (or clear, with null) the staff-assigned priority. Distinct from and
     * never overwrites the priority chosen at ticket creation.
     *
     * @return array<string, mixed>
     */
    public function assignPriority(TicketIssue $ticketIssue, ?string $priority): array
    {
        $ticketIssue->assigned_priority = $priority;
        $ticketIssue->save();

        return $this->show($ticketIssue);
    }

    /**
     * Cancel every open issue on a ticket. Closed issues stay closed -- the
     * work on them happened -- so a ticket with one closed and one cancelled
     * issue reads as closed.
     */
    public function cancelAll(Ticket $ticket, string $reason): void
    {
        DB::transaction(function () use ($ticket, $reason) {
            $ticket->ticketIssues()
                ->whereNotIn('status', array_map(fn (IssueStatus $s) => $s->value, IssueStatus::terminal()))
                ->get()
                ->each(fn (TicketIssue $issue) => TicketStatusService::changeIssueStatus($issue, IssueStatus::Cancelled, $reason));
        });
    }

    /**
     * Cancel an issue (records the reason in its status history).
     *
     * @return array<string, mixed>
     */
    public function cancel(TicketIssue $ticketIssue, string $reason): array
    {
        $this->workflow->cancel($ticketIssue, $reason);

        return $this->show($ticketIssue);
    }

    /**
     * Earlier tickets at a store that reported the same catalog issue -- "the
     * last Oven tickets for this store" -- newest first, one row per ticket.
     *
     * Paginated by TICKET, not by issue row: a ticket can carry the same catalog issue twice
     * ("oven 1", "oven 2"), so issue rows would show one ticket several times.
     * Each row folds its matching issues together and reports the newest one's
     * status (a converted v1 deferral chain ends on its newest issue).
     *
     * Archived (soft-deleted) tickets are left out; so is `$excludeTicketId`,
     * normally the ticket being looked at.
     */
    public function history(Store $store, Issue $issue, ?int $excludeTicketId, bool $openOnly, int $perPage): LengthAwarePaginator
    {
        $terminal = array_map(fn (IssueStatus $s) => $s->value, IssueStatus::terminal());

        return Ticket::query()
            ->where('store_id', $store->id)
            ->whereHas('ticketIssues', fn ($q) => $q->where('issue_id', $issue->id))
            ->when($excludeTicketId, fn ($q) => $q->whereKeyNot($excludeTicketId))
            ->when($openOnly, fn ($q) => $q->whereHas(
                'ticketIssues',
                fn ($i) => $i->where('issue_id', $issue->id)->whereNotIn('status', $terminal)
            ))
            ->with([
                'creator',
                // Only this catalog issue's rows. Nothing below may derive the
                // TICKET's status from this relation -- it is deliberately partial.
                'ticketIssues' => fn ($q) => $q->where('issue_id', $issue->id)
                    ->orderBy('id')
                    ->with(['technicians' => fn ($t) => $t->withTrashed()->select('technicians.id', 'technicians.name')]),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Ticket $ticket) => $this->presentHistoryRow($ticket, $store));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentHistoryRow(Ticket $ticket, Store $store): array
    {
        $rows = $ticket->ticketIssues;
        $latest = $rows->sortByDesc('id')->first();

        $technicians = $rows->flatMap(fn (TicketIssue $i) => $i->technicians)
            ->unique('id')
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
            ->values()
            ->all();

        return [
            'ticket_id' => $ticket->id,
            'store_number' => $store->store_number,
            'created_at' => $ticket->created_at,
            'creator' => $ticket->creator ? $this->catalog->presentUser($ticket->creator) : null,
            // Where this issue stands now on that ticket (the end of any chain).
            'status' => $latest
                ? ['value' => $latest->status->value, 'label' => $latest->status->label()]
                : null,
            'completed_at' => $latest && $latest->status === IssueStatus::Closed ? $latest->closed_at : null,
            'technicians' => $technicians,
            'issues' => $rows->map(fn (TicketIssue $i) => [
                'id' => $i->id,
                'parent_id' => $i->parent_id,
                'status' => ['value' => $i->status->value, 'label' => $i->status->label()],
                'priority' => ['value' => $i->priority->value, 'label' => $i->priority->label()],
                'assigned_priority' => $i->assigned_priority
                    ? ['value' => $i->assigned_priority->value, 'label' => $i->assigned_priority->label()]
                    : null,
                'description' => Str::limit((string) $i->description, 160),
                'completed_at' => $i->status === IssueStatus::Closed ? $i->closed_at : null,
                'created_at' => $i->created_at,
            ])->values()->all(),
        ];
    }

    /**
     * Validation helper: returns the ids that do NOT belong to the ticket.
     *
     * @param  array<int|string>  $ids
     * @return array<int>
     */
    public function issuesNotBelongingToTicket(Ticket $ticket, array $ids): array
    {
        $ids = array_map('intval', $ids);
        $valid = $ticket->ticketIssues()->whereIn('id', $ids)->pluck('id')->all();

        return array_values(array_diff($ids, $valid));
    }

    /**
     * A record named in the URL -- an assignment, a diagnosis, a visit -- must
     * be on one of this ticket's issues, and the ticket must be the store's.
     * Leaf routes bind those records by id alone, so their FormRequests call
     * this. 404 rather than 403: whether that record exists is none of this
     * URL's business.
     */
    public function assertRecordOnTicket(mixed $store, mixed $ticket, ?Model $record = null): void
    {
        if (!$store instanceof Store || !$ticket instanceof Ticket) {
            return;
        }

        abort_unless((int) $ticket->store_id === (int) $store->id, 404);

        if ($record !== null) {
            abort_unless($record->ticketIssues()->where('ticket_issues.ticket_id', $ticket->id)->exists(), 404);
        }
    }

    /**
     * Add a validation error if any of the ids do not belong to the ticket.
     * Called from FormRequests' withValidator() so the rule lives in the service.
     *
     * @param  array<int|string>  $ids
     */
    public function validateIssuesBelongToTicket(Validator $validator, ?Ticket $ticket, array $ids): void
    {
        if (!$ticket || empty($ids)) {
            return;
        }

        $invalid = $this->issuesNotBelongingToTicket($ticket, $ids);

        if (!empty($invalid)) {
            $validator->errors()->add(
                'ticket_issue_ids',
                'The selected issues do not all belong to this ticket: ' . implode(', ', $invalid) . '.'
            );
        }
    }

    /**
     * The issue as the API shows it: the full read when its relations are
     * loaded (TicketIssuePresenter::with()), the core fields otherwise.
     *
     * @return array<string, mixed>
     */
    public function present(TicketIssue $issue): array
    {
        return $this->presenter->present($issue);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentWarranty(Warranty $warranty): array
    {
        return $this->presenter->warranty($warranty);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStatusChange(IssueStatusChange $change): array
    {
        return $this->presenter->statusChange($change);
    }

    /**
     * The issue pickers' lookup (`GET ticket-issues`): a light row per issue,
     * newest first, across every store -- never the full read.
     *
     *   search    the catalog title, the free-text title, the description, or
     *             the ticket id (digits, a leading # allowed)
     *   store     a store number
     *   statuses  any of IssueStatus
     *   limit     default 20, at most 100
     *
     * Issues of a deleted ticket are left out.
     *
     * @param  array{search?: string|null, store?: string|null, statuses?: array<int, string>|null, limit?: int|string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function lookup(array $filters): array
    {
        $query = TicketIssue::query()
            ->whereHas('ticket')
            ->with(['issue', 'ticket.store']);

        $store = trim((string) ($filters['store'] ?? ''));
        if ($store !== '') {
            $query->whereHas('ticket.store', fn ($q) => $q->where('store_number', $store));
        }

        if (! empty($filters['statuses'])) {
            $query->whereIn('status', array_values((array) $filters['statuses']));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $search)).'%';
            $number = ltrim($search, '#');

            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(other_title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                ->orWhereHas('issue', fn ($c) => $c->whereRaw('LOWER(title) LIKE ?', [$like]))
                ->when(ctype_digit($number), fn ($id) => $id->orWhere('ticket_id', (int) $number)));
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));

        return $query->orderByDesc('id')->limit($limit)->get()
            ->map(fn (TicketIssue $i) => [
                'id' => $i->id,
                'ticket_id' => $i->ticket_id,
                'title' => $i->displayTitle(),
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'priority' => $i->priority === null ? null : ['value' => $i->priority->value, 'label' => $i->priority->label()],
                'store' => $i->ticket?->store ? ['id' => $i->ticket->store->id, 'store_number' => $i->ticket->store->store_number] : null,
                'other_store' => $i->ticket?->other_store,
            ])
            ->all();
    }
}
