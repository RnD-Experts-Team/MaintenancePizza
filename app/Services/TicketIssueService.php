<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\IssueStatusChange;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        'statusChanges',
        'notes.attachments',
        'notes.creator',
        'attachments',
        'diagnoses.attachments',
        'diagnoses.notes.attachments',
        'diagnoses.notes.creator',
        'attendanceEntries.technician',
        'attendanceEntries.attachments',
        'attendanceEntries.notes.attachments',
        'attendanceEntries.notes.creator',
        'partUsages.part',
        'partUsages.attachments',
        'partUsages.notes.attachments',
        'partUsages.notes.creator',
        'payEntries.technician',
        'payEntries.attachments',
        'payEntries.notes.attachments',
        'payEntries.notes.creator',
        'warranties.attachments',
        'warranties.notes.attachments',
        'warranties.notes.creator',
        'assignments.delays',
        'assignments.attachments',
        'assignments.notes.attachments',
        'assignments.notes.creator',
        'technicians',
    ];

    public function __construct(
        private WorkflowRecordService $workflow,
        private AssignmentService $assignments,
        private CatalogService $catalog,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function index(Ticket $ticket): array
    {
        return $ticket->ticketIssues()->with($this->detailWith)->get()
            ->map(fn (TicketIssue $i) => $this->present($i))->all();
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

        return $issues->load(['issue', 'statusChanges'])
            ->map(fn (TicketIssue $i) => $this->present($i))->all();
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

        return $this->present($child->load(['issue', 'parent']));
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
                ->each(fn (TicketIssue $issue) => TicketStatusService::changeIssueStatus($issue, IssueStatus::Cancelled, $reason));
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

        return $this->present($ticketIssue->load(['issue', 'statusChanges']));
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
        $technicians = collect($technicianIds)->mapWithKeys(fn ($id) => [$id => $pivot])->all();
        $issues = $ticket->ticketIssues()->whereIn('id', $ticketIssueIds)->get();

        DB::transaction(function () use ($issues, $technicians) {
            foreach ($issues as $issue) {
                $issue->technicians()->syncWithoutDetaching($technicians);
            }
        });

        return $issues->load('technicians')->map(fn (TicketIssue $i) => $this->present($i))->all();
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
     * Add a validation error if any of the ids do not belong to the ticket.
     * Called from FormRequests' withValidator() so the rule lives in the service.
     *
     * @param  array<int|string>  $ids
     */
    public function validateIssuesBelongToTicket(Validator $validator, ?Ticket $ticket, array $ids): void
    {
        if (! $ticket || empty($ids)) {
            return;
        }

        $invalid = $this->issuesNotBelongingToTicket($ticket, $ids);

        if (! empty($invalid)) {
            $validator->errors()->add(
                'ticket_issue_ids',
                'The selected issues do not all belong to this ticket: '.implode(', ', $invalid).'.'
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
            'description' => $issue->description,
            'status' => ['value' => $issue->status->value, 'label' => $issue->status->label()],
            'parent_id' => $issue->parent_id,
            'children' => $issue->relationLoaded('children')
                ? $issue->children->map(fn (TicketIssue $c) => $this->present($c))->all()
                : null,
            'diagnoses' => $this->mapLoaded($issue, 'diagnoses', fn ($d) => $this->workflow->presentDiagnosis($d)),
            'attendance_entries' => $this->mapLoaded($issue, 'attendanceEntries', fn ($a) => $this->workflow->presentAttendance($a)),
            'part_usages' => $this->mapLoaded($issue, 'partUsages', fn ($p) => $this->workflow->presentPartUsage($p)),
            'pay_entries' => $this->mapLoaded($issue, 'payEntries', fn ($p) => $this->workflow->presentPayEntry($p)),
            'warranties' => $this->mapLoaded($issue, 'warranties', fn ($w) => $this->workflow->presentWarranty($w)),
            'assignments' => $this->mapLoaded($issue, 'assignments', fn ($a) => $this->assignments->present($a)),
            'technicians' => $this->mapLoaded($issue, 'technicians', fn ($t) => $this->catalog->presentTechnician($t)),
            'status_changes' => $this->mapLoaded($issue, 'statusChanges', fn ($s) => $this->presentStatusChange($s)),
            'notes' => $this->notes->presentMany($issue),
            'attachments' => $this->attachments->presentMany($issue),
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator
                ? $this->catalog->presentUser($issue->creator)
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
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
        if (! $issue->relationLoaded($relation)) {
            return null;
        }

        return $issue->{$relation}->map($present)->all();
    }
}
