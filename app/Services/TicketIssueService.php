<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueStatusChange;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issue-level lifecycle: the full "one look" listing/detail, status changes
 * (with history), deferral (spawns a child), attaching technicians, and the
 * validation helper that confirms issues belong to a ticket.
 */
class TicketIssueService
{
    /**
     * Eager loads for the full per-issue history.
     *
     * @var list<string>
     */
    private array $detailWith = [
        'issue',
        'parent',
        'children',
        'creator',
        'statusChanges.creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
        'diagnoses.creator',
        'diagnoses.attachments.creator',
        'diagnoses.notes.creator',
        'diagnoses.notes.attachments.creator',
        'attendanceEntries.creator',
        // Without this every session reaches the ticket page with events: [],
        // which reads as "nothing recorded" -- the stream shows nothing and a
        // still-open session is indistinguishable from a closed one.
        'attendanceEntries.events',
        'attendanceEntries.dailyPayPayments.entry',
        'attendanceEntries.dailyPayPayments.technician',
        'attendanceEntries.technician',
        'attendanceEntries.attachments.creator',
        'attendanceEntries.notes.creator',
        'attendanceEntries.notes.attachments.creator',
        'dailyPayLines',
        'partUsages.creator',
        'partUsages.dailyPayPayments.entry',
        'partUsages.dailyPayPayments.technician',
        'partUsages.part',
        'partUsages.paidByTechnician',
        'partUsages.storageLocation',
        'partUsages.returnedToStorageLocation',
        'partUsages.stockMovements',
        'partUsages.attachments.creator',
        'partUsages.notes.creator',
        'partUsages.notes.attachments.creator',
        'payEntries.creator',
        'payEntries.technician',
        'payEntries.attachments.creator',
        'payEntries.notes.creator',
        'payEntries.notes.attachments.creator',
        'warranties.creator',
        'warranties.attachments.creator',
        'warranties.notes.creator',
        'warranties.notes.attachments.creator',
        'assignments.creator',
        'assignments.delays.creator',
        'assignments.attachments.creator',
        'assignments.notes.creator',
        'assignments.notes.attachments.creator',
        'technicians.creator',
    ];

    public function __construct(
        private WorkflowRecordService $workflow,
        private AssignmentService $assignments,
        private CatalogService $catalog,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function index(Ticket $ticket): array
    {
        return $ticket->ticketIssues()->with($this->detailWith)->get()
            ->map(fn(TicketIssue $i) => $this->present($i))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(TicketIssue $ticketIssue): array
    {
        return $this->present($ticketIssue->load($this->detailWith));
    }

    /**
     * Apply a status to one or many issues (the ticket status then derives).
     *
     * @param  array<int>  $ticketIssueIds
     * @return array<int, array<string, mixed>>
     */
    public function changeStatuses(Ticket $ticket, array $ticketIssueIds, string $status): array
    {
        $to = IssueStatus::from($status);
        $issues = $ticket->ticketIssues()->whereIn('id', $ticketIssueIds)->get();

        DB::transaction(function () use ($issues, $to) {
            foreach ($issues as $issue) {
                TicketStatusService::changeIssueStatus($issue, $to);
            }
        });

        return $issues->load(['issue', 'creator', 'statusChanges.creator'])
            ->map(fn(TicketIssue $i) => $this->present($i))->all();
    }

    /**
     * Defer an issue (records the reason) and spawn a pending child.
     *
     * @return array<string, mixed>
     */
    public function defer(Ticket $ticket, TicketIssue $ticketIssue, string $reason): array
    {
        $child = DB::transaction(function () use ($ticket, $ticketIssue, $reason) {
            TicketStatusService::changeIssueStatus($ticketIssue, IssueStatus::Deferred, $reason);

            $child = new TicketIssue([
                'ticket_id' => $ticket->id,
                'issue_id' => $ticketIssue->issue_id,
                'other_title' => $ticketIssue->other_title,
                'priority' => $ticketIssue->priority->value,
                'description' => $ticketIssue->description,
                'status' => IssueStatus::Pending->value,
                'parent_id' => $ticketIssue->id,
            ]);
            $child->created_by = Auth::id();
            $child->save();

            return $child;
        });

        return $this->present($child->load(['issue', 'parent', 'creator']));
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

        return $this->present($ticketIssue->fresh(['issue', 'creator']));
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

        return $this->present($ticketIssue->fresh(['issue', 'creator']));
    }

    /**
     * Cancel all non-cancelled issues on a ticket, making its derived status Cancelled.
     */
    public function cancelAll(Ticket $ticket, string $reason): void
    {
        DB::transaction(function () use ($ticket, $reason) {
            $ticket->ticketIssues()
                ->where('status', '!=', IssueStatus::Cancelled->value)
                ->get()
                ->each(fn(TicketIssue $issue) => TicketStatusService::changeIssueStatus($issue, IssueStatus::Cancelled, $reason));
        });
    }

    /**
     * Cancel an issue (records the reason). Unlike deferral it spawns no child.
     *
     * @return array<string, mixed>
     */
    public function cancel(TicketIssue $ticketIssue, string $reason): array
    {
        DB::transaction(function () use ($ticketIssue, $reason) {
            TicketStatusService::changeIssueStatus($ticketIssue, IssueStatus::Cancelled, $reason);
        });

        return $this->present($ticketIssue->load(['issue', 'creator', 'statusChanges.creator']));
    }

    /**
     * Mark an issue as Waiting (records the reason it's blocked). Unlike
     * deferral it spawns no child — the same issue resumes once unblocked.
     *
     * @return array<string, mixed>
     */
    public function wait(TicketIssue $ticketIssue, string $reason): array
    {
        DB::transaction(function () use ($ticketIssue, $reason) {
            TicketStatusService::changeIssueStatus($ticketIssue, IssueStatus::Waiting, $reason);
        });

        return $this->present($ticketIssue->load(['issue', 'creator', 'statusChanges.creator']));
    }

    /**
     * Attach technicians to one or more issues (no schedule).
     *
     * @param  array<int>  $ticketIssueIds
     * @param  array<int>  $technicianIds
     * @return array<int, array<string, mixed>>
     */
    public function attachTechnicians(Ticket $ticket, array $ticketIssueIds, array $technicianIds): array
    {
        $pivot = ['created_by' => Auth::id()];
        $technicians = collect($technicianIds)->mapWithKeys(fn($id) => [$id => $pivot])->all();
        $issues = $ticket->ticketIssues()->whereIn('id', $ticketIssueIds)->get();

        DB::transaction(function () use ($issues, $technicians) {
            foreach ($issues as $issue) {
                $issue->technicians()->syncWithoutDetaching($technicians);
            }
        });

        return $issues->load(['creator', 'technicians.creator'])->map(fn(TicketIssue $i) => $this->present($i))->all();
    }

    /**
     * Earlier tickets at a store that reported the same catalog issue -- "the
     * last Oven tickets for this store" -- newest first, one row per ticket.
     *
     * Paginated by TICKET, not by issue row: a deferral spawns a child issue on
     * the same ticket, and a ticket can carry the same catalog issue twice
     * ("oven 1", "oven 2"), so issue rows would show one ticket several times.
     * Each row folds its matching issues together and reports the newest one's
     * status, which is where a deferral chain currently stands.
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
                    ->with(['technicians' => fn ($t) => $t->withTrashed()->select('technicians.id', 'technicians.name')])
                    ->withMax(
                        ['statusChanges as completed_at' => fn ($s) => $s->where('to_status', IssueStatus::Complete->value)],
                        'created_at'
                    ),
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
            'completed_at' => $latest && $latest->status === IssueStatus::Complete ? $this->timestamp($latest->completed_at) : null,
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
                'completed_at' => $i->status === IssueStatus::Complete ? $this->timestamp($i->completed_at) : null,
                'created_at' => $i->created_at,
            ])->values()->all(),
        ];
    }

    /**
     * An aggregate's raw DB datetime as a Carbon instance, so it serialises in
     * the same ISO-8601 UTC shape as every model timestamp in the API.
     */
    private function timestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        return $value === null || $value === '' ? null : \Illuminate\Support\Carbon::parse($value);
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
     * The relaxed counterpart of validateIssuesBelongToTicket(): the record may
     * span tickets, so long as it touches the one it is being filed under.
     *
     * Used by attendance, where a single visit legitimately covers issues on
     * several tickets at the same store — one drive out, three tickets worked.
     * Everything else (parts, assignments, diagnoses) still uses the strict
     * check, which is why this is a separate method rather than a flag.
     *
     * @param  array<int|string>  $ids
     */
    public function validateAtLeastOneIssueBelongsToTicket(Validator $validator, ?Ticket $ticket, array $ids): void
    {
        if (!$ticket || empty($ids)) {
            return;
        }

        $invalid = $this->issuesNotBelongingToTicket($ticket, $ids);

        if (count($invalid) === count($ids)) {
            $validator->errors()->add(
                'ticket_issue_ids',
                'At least one of the selected issues must belong to this ticket.'
            );
        }
    }

    /**
     * Add a validation error for any id that is not a real ticket issue. The
     * cross-ticket entry points have no parent ticket to check against, so
     * existence is the only structural rule left.
     *
     * @param  array<int|string>  $ids
     */
    public function validateIssuesExist(Validator $validator, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $ids = array_map('intval', $ids);
        $found = TicketIssue::query()->whereIn('id', $ids)->pluck('id')->all();
        $missing = array_values(array_diff($ids, $found));

        if (!empty($missing)) {
            $validator->errors()->add(
                'ticket_issue_ids',
                'These issues do not exist: ' . implode(', ', $missing) . '.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'ticket_id' => $issue->ticket_id,
            'issue_id' => $issue->issue_id,
            'issue' => $issue->relationLoaded('issue') && $issue->issue
                ? $this->catalog->presentIssue($issue->issue)
                : null,
            'other_title' => $issue->other_title,
            'display_title' => $issue->displayTitle(),
            'priority' => ['value' => $issue->priority->value, 'label' => $issue->priority->label()],
            'assigned_priority' => $issue->assigned_priority
                ? ['value' => $issue->assigned_priority->value, 'label' => $issue->assigned_priority->label()]
                : null,
            'description' => $issue->description,
            'status' => ['value' => $issue->status->value, 'label' => $issue->status->label()],
            // Whether everything this issue costs anybody is on a pay sheet yet.
            'payment' => $this->presentIssuePaymentStatus($issue),
            'parent_id' => $issue->parent_id,
            'children' => $issue->relationLoaded('children')
                ? $issue->children->map(fn(TicketIssue $c) => $this->present($c))->all()
                : null,
            'diagnoses' => $this->mapLoaded($issue, 'diagnoses', fn($d) => $this->workflow->presentDiagnosis($d)),
            'attendance_entries' => $this->mapLoaded($issue, 'attendanceEntries', fn($a) => $this->workflow->presentAttendance($a)),
            'part_usages' => $this->mapLoaded($issue, 'partUsages', fn($p) => $this->workflow->presentPartUsage($p)),
            'pay_entries' => $this->mapLoaded($issue, 'payEntries', fn($p) => $this->workflow->presentPayEntry($p)),
            'warranties' => $this->mapLoaded($issue, 'warranties', fn($w) => $this->workflow->presentWarranty($w)),
            'assignments' => $this->mapLoaded($issue, 'assignments', fn($a) => $this->assignments->present($a)),
            'technicians' => $this->mapLoaded($issue, 'technicians', fn($t) => $this->catalog->presentTechnician($t)),
            'status_changes' => $this->mapLoaded($issue, 'statusChanges', fn($s) => $this->presentStatusChange($s)),
            'notes' => $this->notes->presentMany($issue),
            'attachments' => $this->attachments->presentMany($issue),
            // The troubleshooting the manager confirmed trying before opening
            // the ticket, as it read then. Null when none was asked for.
            'troubleshooting_confirmed_at' => $issue->troubleshooting_confirmed_at,
            'troubleshooting_snapshot' => $issue->troubleshooting_snapshot,
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator
                ? $this->catalog->presentUser($issue->creator)
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
        ];
    }

    /**
     * The issue's own payment position: whether its hours and the parts a
     * technician fronted are on a pay sheet yet, and which pay lines cover it.
     *
     * Anything still owed dominates the roll-up, so an issue reads as unpaid
     * until the last of its payables is settled.
     *
     * @return array<string, mixed>|null
     */
    private function presentIssuePaymentStatus(TicketIssue $issue): ?array
    {
        $status = $issue->paymentStatus();

        if ($status === null) {
            return null;
        }

        return [
            'status' => ['value' => $status->value, 'label' => $status->label()],
            'daily_pay_line_ids' => $issue->relationLoaded('dailyPayLines')
                ? $issue->dailyPayLines->pluck('id')->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStatusChange(IssueStatusChange $change): array
    {
        return [
            'id' => $change->id,
            'ticket_issue_id' => $change->ticket_issue_id,
            'from_status' => $change->from_status?->value,
            'to_status' => $change->to_status->value,
            'reason' => $change->reason,
            'created_by' => $change->created_by,
            'creator' => $change->relationLoaded('creator') && $change->creator
                ? $this->catalog->presentUser($change->creator)
                : null,
            'created_at' => $change->created_at,
        ];
    }

    /**
     * Map a loaded relation through a presenter, or null when not loaded.
     *
     * @return array<int, mixed>|null
     */
    private function mapLoaded(TicketIssue $issue, string $relation, callable $present): ?array
    {
        if (!$issue->relationLoaded($relation)) {
            return null;
        }

        return $issue->{$relation}->map($present)->all();
    }
}
