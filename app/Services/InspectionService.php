<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Enums\IssueStatus;
use App\Models\IssueInspection;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripStopIssue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inspections (docs/MAINTENANCE-V2.md §2): booking a technician to go and
 * look, and recording what he found. An issue can have several (a second
 * opinion); each is booked like work -- a kind=inspection booking, the issue
 * on the technician's trip for that day -- so the technician sees it on the
 * same link as his jobs.
 */
class InspectionService
{
    public function __construct(
        private IssueWorkflowService $workflow,
        private AssignmentService $assignments,
        private AttachmentService $attachments,
    ) {
    }

    /**
     * @param  array{technician_id: int, scheduled_date: string, scheduled_time?: string|null, instructions?: string|null}  $data
     * @return array{inspection: IssueInspection, trip: Trip|null, token: string|null}
     */
    public function book(TicketIssue $issue, array $data, User $by): array
    {
        return DB::transaction(function () use ($issue, $data, $by) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::INSPECTION);

            $technician = Technician::query()->findOrFail($data['technician_id']);

            $inspection = IssueInspection::query()->create([
                'ticket_issue_id' => $issue->id,
                'technician_id' => $technician->id,
                'status' => InspectionStatus::Scheduled,
                'scheduled_date' => Carbon::parse($data['scheduled_date'])->toDateString(),
                'scheduled_time' => AssignmentService::time($data['scheduled_time'] ?? null),
                'created_by' => $by->id,
            ]);

            $booked = $this->assignments->book(
                $issue,
                'inspection',
                $technician,
                $data['scheduled_date'],
                $data['scheduled_time'] ?? null,
                null,
                $data['instructions'] ?? null,
                $by,
                $inspection,
            );

            $issue->next_action = null;
            $issue->save();

            if ($issue->status !== IssueStatus::Inspection) {
                TicketStatusService::changeIssueStatus($issue, IssueStatus::Inspection);
            }

            return ['inspection' => $inspection, 'trip' => $booked['trip'], 'token' => $booked['token']];
        });
    }

    /**
     * What the inspection found. Marks it done; the issue's status does not
     * change (the next step -- a quote to approve, or straight to the work --
     * is the office's call). The inspection's task on the technician's trip
     * is ticked done too, as he would have from his link.
     *
     * @param  array{findings: string, recommended_repair?: string|null, parts_needed?: string|null, estimated_cost?: string|float|null}  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function recordResult(TicketIssue $issue, IssueInspection $inspection, array $data, array $files): IssueInspection
    {
        return DB::transaction(function () use ($issue, $inspection, $data, $files) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::INSPECTION_RESULT);

            if ($inspection->mistaken) {
                $this->workflow->refuse('This inspection was marked as entered by mistake.');
            }

            if ($inspection->status !== InspectionStatus::Scheduled) {
                $this->workflow->refuse('This inspection is already '.strtolower($inspection->status->label()).'.');
            }

            $inspection->fill([
                'findings' => $data['findings'],
                'recommended_repair' => $data['recommended_repair'] ?? null,
                'parts_needed' => $data['parts_needed'] ?? null,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'status' => InspectionStatus::Done,
                'done_at' => now(),
            ])->save();

            $this->attachments->store($inspection, $files);

            $tripIds = $inspection->assignments()->whereNotNull('trip_id')->pluck('trip_id')->all();
            if ($tripIds !== []) {
                TripStopIssue::query()
                    ->whereHas('stop', fn ($q) => $q->whereIn('trip_id', $tripIds))
                    ->where('ticket_issue_id', $issue->id)
                    ->where('purpose', 'inspection')
                    ->where('task_status', 'pending')
                    ->get()
                    ->each(fn (TripStopIssue $row) => $row->forceFill(['task_status' => 'done', 'done_at' => now()])->save());
            }

            return $inspection;
        });
    }
}
