<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Assignment;
use App\Models\AssignmentDelay;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Scheduling: creating assignments (which attach technicians and move issues to
 * Assigned), delaying them (with history), and changing technicians without a
 * reschedule. Status transitions go through TicketStatusService (static).
 */
class AssignmentService
{
    /**
     * Eager loads for an assignment's own notes/files and its delays' notes/files.
     *
     * @var list<string>
     */
    private const RECORD_LOADS = [
        'creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
        'delays.creator',
        'delays.notes.creator',
        'delays.notes.attachments.creator',
        'delays.attachments.creator',
    ];

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  ticket_issue_ids, technician_ids, assigned_date, assigned_hour?
     * @return array<string, mixed>
     */
    public function create(Ticket $ticket, array $data): array
    {
        $pivot = ['created_by' => Auth::id()];

        $assignment = DB::transaction(function () use ($ticket, $data, $pivot) {
            $assignment = new Assignment([
                'assigned_date' => $data['assigned_date'],
                'assigned_hour' => $data['assigned_hour'] ?? null,
            ]);
            $assignment->created_by = Auth::id();
            $assignment->save();

            $assignment->ticketIssues()->attach($data['ticket_issue_ids']);

            $technicians = collect($data['technician_ids'])->mapWithKeys(fn($id) => [$id => $pivot])->all();
            $issues = $ticket->ticketIssues()->whereIn('id', $data['ticket_issue_ids'])->get();

            foreach ($issues as $issue) {
                $issue->technicians()->syncWithoutDetaching($technicians);
                TicketStatusService::changeIssueStatus($issue, IssueStatus::Assigned);
            }

            return $assignment;
        });

        return $this->present($assignment->load(['delays', 'ticketIssues', ...self::RECORD_LOADS]));
    }

    /**
     * @param  array<string, mixed>  $data  new_date, new_hour?, reason
     * @return array<string, mixed>
     */
    public function delay(Assignment $assignment, array $data): array
    {
        DB::transaction(function () use ($assignment, $data) {
            $delay = new AssignmentDelay([
                'assignment_id' => $assignment->id,
                'old_date' => $assignment->assigned_date,
                'old_hour' => $assignment->assigned_hour,
                'new_date' => $data['new_date'],
                'new_hour' => $data['new_hour'] ?? null,
                'reason' => $data['reason'],
            ]);
            $delay->created_by = Auth::id();
            $delay->save();

            $assignment->update([
                'assigned_date' => $data['new_date'],
                'assigned_hour' => $data['new_hour'] ?? null,
            ]);
        });

        return $this->present($assignment->load(['delays', ...self::RECORD_LOADS]));
    }

    /**
     * Mark an assignment as entered in error.
     *
     * @return array<string, mixed>
     */
    public function markAssignmentMistaken(Assignment $assignment): array
    {
        $assignment->update(['mistaken' => true]);

        return $this->present($assignment->load(['delays', ...self::RECORD_LOADS]));
    }

    /**
     * Mark a delay record as entered in error.
     *
     * @return array<string, mixed>
     */
    public function markDelayMistaken(AssignmentDelay $delay): array
    {
        $delay->update(['mistaken' => true]);

        return $this->presentDelay($delay->load(['notes.attachments', 'notes', 'attachments']));
    }

    /**
     * Replace technicians on the assignment's issues — NOT a delay.
     *
     * @param  array<int>  $technicianIds
     * @return array<string, mixed>
     */
    public function changeTechnicians(Assignment $assignment, array $technicianIds): array
    {
        $pivot = ['created_by' => Auth::id()];
        $technicians = collect($technicianIds)->mapWithKeys(fn($id) => [$id => $pivot])->all();

        DB::transaction(function () use ($assignment, $technicians) {
            $assignment->load('ticketIssues');

            foreach ($assignment->ticketIssues as $issue) {
                $issue->technicians()->sync($technicians);
            }
        });

        return $this->present($assignment->load(['delays', 'ticketIssues', ...self::RECORD_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'assigned_date' => $assignment->assigned_date?->toDateString(),
            'assigned_hour' => $assignment->assigned_hour,
            'mistaken' => $assignment->mistaken,
            'delays' => $assignment->relationLoaded('delays')
                ? $assignment->delays->map(fn(AssignmentDelay $d) => $this->presentDelay($d))->all()
                : null,
            'ticket_issue_ids' => $assignment->relationLoaded('ticketIssues')
                ? $assignment->ticketIssues->pluck('id')->all()
                : null,
            'notes' => $this->notes->presentMany($assignment),
            'attachments' => $this->attachments->presentMany($assignment),
            'created_by' => $assignment->created_by,
            'creator' => $assignment->relationLoaded('creator') && $assignment->creator
                ? ['id' => $assignment->creator->id, 'name' => $assignment->creator->name, 'email' => $assignment->creator->email]
                : null,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDelay(AssignmentDelay $delay): array
    {
        return [
            'id' => $delay->id,
            'assignment_id' => $delay->assignment_id,
            'old_date' => $delay->old_date?->toDateString(),
            'old_hour' => $delay->old_hour,
            'new_date' => $delay->new_date?->toDateString(),
            'new_hour' => $delay->new_hour,
            'reason' => $delay->reason,
            'mistaken' => $delay->mistaken,
            'notes' => $this->notes->presentMany($delay),
            'attachments' => $this->attachments->presentMany($delay),
            'created_by' => $delay->created_by,
            'creator' => $delay->relationLoaded('creator') && $delay->creator
                ? ['id' => $delay->creator->id, 'name' => $delay->creator->name, 'email' => $delay->creator->email]
                : null,
            'created_at' => $delay->created_at,
        ];
    }
}
