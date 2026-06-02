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

            $technicians = collect($data['technician_ids'])->mapWithKeys(fn ($id) => [$id => $pivot])->all();
            $issues = $ticket->ticketIssues()->whereIn('id', $data['ticket_issue_ids'])->get();

            foreach ($issues as $issue) {
                $issue->technicians()->syncWithoutDetaching($technicians);
                TicketStatusService::changeIssueStatus($issue, IssueStatus::Assigned);
            }

            return $assignment;
        });

        return $this->present($assignment->load(['delays', 'ticketIssues']));
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

        return $this->present($assignment->load('delays'));
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
        $technicians = collect($technicianIds)->mapWithKeys(fn ($id) => [$id => $pivot])->all();

        DB::transaction(function () use ($assignment, $technicians) {
            $assignment->load('ticketIssues');

            foreach ($assignment->ticketIssues as $issue) {
                $issue->technicians()->sync($technicians);
            }
        });

        return $this->present($assignment->load(['delays', 'ticketIssues']));
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
            'delays' => $assignment->relationLoaded('delays')
                ? $assignment->delays->map(fn (AssignmentDelay $d) => $this->presentDelay($d))->all()
                : null,
            'ticket_issue_ids' => $assignment->relationLoaded('ticketIssues')
                ? $assignment->ticketIssues->pluck('id')->all()
                : null,
            'created_by' => $assignment->created_by,
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
            'created_by' => $delay->created_by,
            'created_at' => $delay->created_at,
        ];
    }
}
