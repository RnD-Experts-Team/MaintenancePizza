<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Assignment;
use App\Models\AssignmentDelay;
use App\Models\IssueInspection;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\TripStopIssue;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bookings -- "schedules" in the v2 contract (docs/MAINTENANCE-V2.md §2): a
 * technician, a day (and optional hour), `kind` work or inspection, and the
 * trip that carries it.
 *
 * Booking always goes through TripService::scheduleIssue, which puts the
 * issue on the technician's open trip for that day (creating the trip, and a
 * link for it, when there is none). The hour lives here, on the booking:
 * trips have no time. Moving a booking keeps the old date as an
 * AssignmentDelay and moves the issue to the technician's trip for the new
 * day.
 */
class AssignmentService
{
    public function __construct(
        private TripService $trips,
        private IssueWorkflowService $workflow,
    ) {
    }

    /**
     * Schedule the work (contract: `schedule`): a kind=work booking, the
     * issue on the technician's trip, the technician on the issue, and the
     * issue → scheduled.
     *
     * @param  array{technician_id: int, date: string, time?: string|null, expected_completion_date?: string|null, instructions?: string|null}  $data
     * @return array{assignment: Assignment, trip: Trip|null, token: string|null}
     */
    public function scheduleWork(TicketIssue $issue, array $data, User $by): array
    {
        return DB::transaction(function () use ($issue, $data, $by) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::SCHEDULE);

            $result = $this->book(
                $issue,
                'work',
                Technician::query()->findOrFail($data['technician_id']),
                $data['date'],
                $data['time'] ?? null,
                $data['expected_completion_date'] ?? null,
                $data['instructions'] ?? null,
                $by,
            );

            $issue->next_action = null;
            $issue->save();

            if ($issue->status !== IssueStatus::Scheduled) {
                TicketStatusService::changeIssueStatus($issue, IssueStatus::Scheduled);
            }

            return $result;
        });
    }

    /**
     * One booking: the Assignment, the issue on the technician's trip for
     * that day, and the technician put on the issue. The caller has checked
     * the status and moves it.
     *
     * @return array{assignment: Assignment, trip: Trip|null, token: string|null}
     */
    public function book(
        TicketIssue $issue,
        string $kind,
        Technician $technician,
        string $date,
        ?string $time,
        ?string $expectedCompletion,
        ?string $instructions,
        User $by,
        ?IssueInspection $inspection = null,
    ): array {
        $assignment = new Assignment([
            'kind' => $kind,
            'technician_id' => $technician->id,
            'assigned_date' => Carbon::parse($date)->toDateString(),
            'assigned_hour' => self::time($time),
            'expected_completion_date' => $expectedCompletion ? Carbon::parse($expectedCompletion)->toDateString() : null,
            'issue_inspection_id' => $inspection?->id,
        ]);
        $assignment->created_by = $by->id;
        $assignment->save();
        $assignment->ticketIssues()->attach($issue->id);

        $scheduled = $this->trips->scheduleIssue($issue, $technician, $date, $time, $kind, $instructions, $by);

        $assignment->trip_id = $scheduled['trip']->id;
        $assignment->save();

        $issue->technicians()->syncWithoutDetaching([$technician->id => ['created_by' => $by->id]]);

        return ['assignment' => $assignment, 'trip' => $scheduled['trip'], 'token' => $scheduled['token']];
    }

    /**
     * Move a booking to another day and/or hour (contract:
     * `schedules/{assignment}/move`). The old day is kept as an
     * AssignmentDelay with the reason. When the DAY changes the issue leaves
     * the old trip (only while its task there is still pending -- a visit
     * that happened stays history) and goes on the technician's trip for the
     * new day. The issue's status does not change.
     *
     * @param  array{date: string, time?: string|null, reason: string}  $data
     * @return array{assignment: Assignment, trip: Trip|null, token: string|null}
     */
    public function move(TicketIssue $issue, Assignment $assignment, array $data, User $by): array
    {
        return DB::transaction(function () use ($issue, $assignment, $data, $by) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::MOVE);

            if ($assignment->mistaken) {
                $this->workflow->refuse('This booking was marked as entered by mistake, so it cannot be moved.');
            }

            $newDate = Carbon::parse($data['date'])->toDateString();
            $newHour = self::time($data['time'] ?? null);
            $oldDate = $assignment->assigned_date?->toDateString();

            if ($newDate === $oldDate && $newHour === self::time($assignment->assigned_hour)) {
                $this->workflow->refuse('The booking is already on that day and time.', 'date');
            }

            $delay = new AssignmentDelay([
                'assignment_id' => $assignment->id,
                'old_date' => $oldDate,
                'old_hour' => $assignment->assigned_hour,
                'new_date' => $newDate,
                'new_hour' => $newHour,
                'reason' => $data['reason'],
            ]);
            $delay->created_by = $by->id;
            $delay->save();

            $trip = $assignment->trip;
            $token = null;
            $kind = $assignment->kind ?? 'work';
            $technician = $assignment->technician;

            if ($newDate !== $oldDate && $technician !== null) {
                foreach ($assignment->ticketIssues()->get() as $onIt) {
                    if ($assignment->trip_id !== null) {
                        $this->leaveTrip($assignment->trip_id, $onIt, $kind);
                    }

                    $scheduled = $this->trips->scheduleIssue($onIt, $technician, $newDate, $newHour, $kind, null, $by);
                    $trip = $scheduled['trip'];
                    $token ??= $scheduled['token'];
                }
            }

            $assignment->assigned_date = $newDate;
            $assignment->assigned_hour = $newHour;
            $assignment->trip_id = $trip?->id;
            $assignment->save();

            if ($kind === 'inspection' && $assignment->issue_inspection_id !== null) {
                IssueInspection::query()->whereKey($assignment->issue_inspection_id)->update([
                    'scheduled_date' => $newDate,
                    'scheduled_time' => $newHour,
                ]);
            }

            return ['assignment' => $assignment, 'trip' => $trip, 'token' => $token];
        });
    }

    /**
     * Take the issue off a trip for one purpose, while its task there is
     * still pending. A stop left empty that nobody has been to (no arrival,
     * no purchases, no notes or files) goes too, so the technician's page
     * does not send him to a store with nothing to do.
     */
    private function leaveTrip(int $tripId, TicketIssue $issue, string $purpose): void
    {
        $rows = TripStopIssue::query()
            ->whereHas('stop', fn ($q) => $q->where('trip_id', $tripId))
            ->where('ticket_issue_id', $issue->id)
            ->where('purpose', $purpose)
            ->where('task_status', 'pending')
            ->with('stop')
            ->get();

        foreach ($rows as $row) {
            $stop = $row->stop;
            $row->delete();

            if ($stop instanceof TripStop
                && $stop->status === 'planned'
                && $stop->arrived_at === null
                && !$stop->stopIssues()->exists()
                && !$stop->purchases()->exists()
                && !$stop->notes()->exists()
                && !$stop->attachments()->exists()) {
                $stop->delete();
            }
        }
    }

    /**
     * An hour as stored (HH:MM:SS), or null. Accepts HH:MM and HH:MM:SS.
     */
    public static function time(?string $time): ?string
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        return Carbon::createFromFormat(strlen(trim($time)) === 5 ? 'H:i' : 'H:i:s', trim($time))->format('H:i:s');
    }

    /**
     * A stored hour as shown (HH:MM), or null.
     */
    public static function showTime(?string $time): ?string
    {
        return $time === null || $time === '' ? null : substr($time, 0, 5);
    }
}
