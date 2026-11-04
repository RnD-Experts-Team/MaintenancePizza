<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\IssueInspection;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripSession;
use App\Models\TripStop;
use App\Models\TripStopIssue;

/**
 * What happens to trips and bookings when a booking moves to another day, or
 * an issue is closed or cancelled with visits still planned
 * (TripService::removePendingTask / dropIfUnused / releaseFinishedIssue).
 */
class FinishedIssueTripsTest extends IssueWorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-01 12:00:00');
    }

    private function scheduleOn(string $date, ?TicketIssue $issue = null): Assignment
    {
        $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => $date], $issue)->assertOk();

        return Assignment::query()->latest('id')->firstOrFail();
    }

    private function clockOn(Trip $trip): void
    {
        TripSession::query()->create([
            'trip_id' => $trip->id,
            'technician_id' => $trip->technician_id,
            'clock_in' => '2026-11-01 09:00:00',
            'clock_out' => '2026-11-01 10:00:00',
            'source' => 'technician',
        ]);
    }

    public function test_moving_a_booking_removes_the_old_days_trip_when_it_is_left_empty_and_unused(): void
    {
        $assignment = $this->scheduleOn('2026-11-03');
        $oldTrip = Trip::query()->sole();

        $this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-06', 'reason' => 'Part arrives Friday'])->assertOk();

        $this->assertNull(Trip::query()->find($oldTrip->id));
        $this->assertSame('2026-11-06', Trip::query()->sole()->planned_date->toDateString());
    }

    public function test_moving_keeps_the_old_trip_when_it_was_used_or_still_has_stops(): void
    {
        // The link was opened: kept (empty, but someone saw it).
        $assignment = $this->scheduleOn('2026-11-03');
        $opened = Trip::query()->sole();
        $opened->forceFill(['last_used_at' => now()])->save();
        $this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-06', 'reason' => 'x'])->assertOk();
        $this->assertNotNull(Trip::query()->find($opened->id));

        // Clocked time: kept.
        $other = TicketIssue::factory()->for($this->ticket)->create(['other_title' => 'Fryer']);
        $booking = $this->scheduleOn('2026-11-04', $other);
        $clocked = Trip::query()->whereDate('planned_date', '2026-11-04')->sole();
        $this->clockOn($clocked);
        $this->act("schedules/{$booking->id}/move", ['date' => '2026-11-07', 'reason' => 'x'], $other)->assertOk();
        $this->assertNotNull(Trip::query()->find($clocked->id));

        // Another issue still on it: kept, with that issue's visit.
        $third = TicketIssue::factory()->for($this->ticket)->create(['other_title' => 'Hood']);
        $this->scheduleOn('2026-11-10', $third);
        $fourth = TicketIssue::factory()->for($this->ticket)->create(['other_title' => 'Sink']);
        $booking = $this->scheduleOn('2026-11-10', $fourth);
        $shared = Trip::query()->whereDate('planned_date', '2026-11-10')->sole();
        $this->act("schedules/{$booking->id}/move", ['date' => '2026-11-11', 'reason' => 'x'], $fourth)->assertOk();
        $this->assertNotNull(Trip::query()->find($shared->id));
        $this->assertSame(
            [$third->id],
            TripStopIssue::query()->whereHas('stop', fn ($q) => $q->where('trip_id', $shared->id))->pluck('ticket_issue_id')->all(),
        );
    }

    public function test_cancelling_takes_the_planned_visit_off_ends_future_bookings_and_cancels_booked_inspections(): void
    {
        $this->act('inspections', ['technician_id' => $this->technician->id, 'scheduled_date' => '2026-11-03'])->assertOk();
        $trip = Trip::query()->sole();

        $this->act('cancel', ['reason' => 'Store fixed it'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.schedules.0.ended_reason', 'No longer needed: the issue was cancelled.')
            ->assertJsonPath('data.inspections.0.status', 'cancelled')
            ->assertJsonCount(0, 'data.visits');

        $this->assertNotNull(Assignment::query()->sole()->ended_at);
        $this->assertSame('cancelled', IssueInspection::query()->sole()->status->value);
        // Nothing else was on the trip and nobody used it: gone.
        $this->assertNull(Trip::query()->find($trip->id));
        $this->assertSame(0, TripStop::query()->count());
    }

    public function test_closing_with_clocked_time_on_the_trip_marks_the_task_no_longer_needed(): void
    {
        $this->scheduleOn('2026-11-01');
        $trip = Trip::query()->sole();
        $this->clockOn($trip);

        $this->act('close', ['resolution' => 'not_needed', 'final_note' => 'The fan motor was burnt; replaced it.'])->assertOk()->assertJsonPath('data.status', 'closed');

        $row = TripStopIssue::query()->sole();
        $this->assertSame('not_done', $row->task_status);
        $this->assertSame('No longer needed: the issue was closed.', $row->not_done_reason);
        $this->assertNotNull(Trip::query()->find($trip->id));

        // The trip shows it as not done, with the reason.
        $this->getJson("/api/trips/{$trip->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.stops.0.issues.0.task_status', 'not_done')
            ->assertJsonPath('data.stops.0.issues.0.not_done_reason', 'No longer needed: the issue was closed.');
    }

    public function test_done_tasks_and_past_bookings_are_left_alone(): void
    {
        $past = $this->scheduleOn('2026-10-29');
        TripStopIssue::query()->update(['task_status' => 'done', 'done_at' => now()]);
        $pastTrip = Trip::query()->sole();

        $this->act('cancel', ['reason' => 'Done already'])->assertOk();

        $this->assertNull($past->fresh()->ended_at);
        $this->assertSame('done', TripStopIssue::query()->sole()->task_status);
        $this->assertNotNull(Trip::query()->find($pastTrip->id));
    }

    public function test_a_jump_to_closed_releases_the_same_way_and_an_ended_booking_cannot_be_moved(): void
    {
        $assignment = $this->scheduleOn('2026-11-05');

        $this->act('jump', ['status' => 'closed', 'reason' => 'Owner closed it'])->assertOk();
        $this->assertNotNull($assignment->fresh()->ended_at);
        $this->assertSame(0, Trip::query()->count());

        $this->act('jump', ['status' => 'scheduled', 'reason' => 'Reopened'])->assertOk();
        $this->assertRefused($this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-09', 'reason' => 'x']))
            ->assertJsonPath('errors.status.0', 'This booking ended when the issue was finished. Book the work again instead.');
    }
}
