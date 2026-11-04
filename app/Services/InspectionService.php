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
 * Inspections (docs/MAINTENANCE.md §2): booking a technician to go and
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
     * @param  array{technician_id: int, scheduled_date: string, scheduled_time?: string|null, scheduled_time_to?: string|null, inspection_fee?: string|float|null, instructions?: string|null}  $data
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
                'scheduled_time_to' => AssignmentService::time($data['scheduled_time_to'] ?? null),
                'inspection_fee' => isset($data['inspection_fee']) && $data['inspection_fee'] !== '' ? number_format((float) $data['inspection_fee'], 2, '.', '') : null,
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
                $data['scheduled_time_to'] ?? null,
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

    /**
     * The payment dialog's Inspection line (`GET issue-inspections`): the
     * inspections there are to pay for, newest first. Mistaken ones never.
     *
     *   technician_id  who did it
     *   unpaid=1       only those no payment line pays yet
     *   search         the issue's title (catalog or free text) or the ticket
     *                  id (digits, a leading # allowed)
     *   limit          default 50, at most 100
     *
     * `paid` is true when a line of a payment that is not mistaken names the
     * inspection (payment_lines.issue_inspection_id) -- the same test
     * `unpaid=1` filters on, so the two can never disagree.
     *
     * @param  array{technician_id?: int|string|null, unpaid?: bool|string|null, search?: string|null, limit?: int|string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function lookup(array $filters): array
    {
        // A line pays only while its payment is not mistaken.
        $paying = fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('mistaken', false));

        $query = IssueInspection::query()
            ->where('mistaken', false)
            ->whereHas('ticketIssue.ticket')
            ->withExists(['paymentLines as paid' => $paying])
            ->with(['technician', 'ticketIssue.issue', 'ticketIssue.ticket.store']);

        if (! empty($filters['technician_id'])) {
            $query->where('technician_id', (int) $filters['technician_id']);
        }

        if (! empty($filters['unpaid']) && filter_var($filters['unpaid'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereDoesntHave('paymentLines', $paying);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $search)).'%';
            $number = ltrim($search, '#');

            $query->whereHas('ticketIssue', fn ($i) => $i->where(fn ($q) => $q
                ->whereRaw('LOWER(other_title) LIKE ?', [$like])
                ->orWhereHas('issue', fn ($c) => $c->whereRaw('LOWER(title) LIKE ?', [$like]))
                ->when(ctype_digit($number), fn ($id) => $id->orWhere('ticket_id', (int) $number))));
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));

        return $query->orderByDesc('id')->limit($limit)->get()
            ->map(function (IssueInspection $inspection) {
                $issue = $inspection->ticketIssue;
                $store = $issue?->ticket?->store;

                return [
                    'id' => $inspection->id,
                    'ticket_issue' => $issue === null ? null : [
                        'id' => $issue->id,
                        'ticket_id' => $issue->ticket_id,
                        'title' => $issue->displayTitle(),
                        'store' => $store ? ['id' => $store->id, 'store_number' => $store->store_number] : null,
                        'other_store' => $issue->ticket?->other_store,
                    ],
                    'technician' => $inspection->technician
                        ? ['id' => $inspection->technician->id, 'name' => $inspection->technician->name]
                        : null,
                    'status' => $inspection->status->value,
                    'status_label' => $inspection->status->label(),
                    'done_at' => $inspection->done_at,
                    'scheduled_date' => $inspection->scheduled_date?->toDateString(),
                    'estimated_cost' => $inspection->estimated_cost === null
                        ? null
                        : number_format((float) $inspection->estimated_cost, 2, '.', ''),
                    'paid' => (bool) $inspection->paid,
                ];
            })
            ->all();
    }
}
