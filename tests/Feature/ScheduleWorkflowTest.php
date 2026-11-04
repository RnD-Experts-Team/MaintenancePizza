<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Assignment;
use App\Models\AssignmentDelay;
use App\Models\IssueInspection;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\TripStopIssue;

/**
 * Scheduling the work and moving a booking (docs/MAINTENANCE.md §2).
 */
class ScheduleWorkflowTest extends IssueWorkflowTestCase
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function schedule(array $extra = [], ?TicketIssue $issue = null): \Illuminate\Testing\TestResponse
    {
        return $this->act('schedule', $extra + [
            'technician_id' => $this->technician->id,
            'date' => '2026-11-03',
            'time' => '08:00',
            'expected_completion_date' => '2026-11-04',
        ], $issue);
    }

    public function test_scheduling_books_the_work_on_the_technicians_trip(): void
    {
        $this->putStatus(IssueStatus::InReview);
        $this->issue->forceFill(['next_action' => 'Schedule the work'])->save();

        $response = $this->schedule()->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.step', 8)
            ->assertJsonPath('data.next_action', null)
            ->assertJsonPath('data.schedules.0.kind', 'work')
            ->assertJsonPath('data.schedules.0.technician.name', 'Sam Fixer')
            ->assertJsonPath('data.schedules.0.date', '2026-11-03')
            ->assertJsonPath('data.schedules.0.time', '08:00')
            ->assertJsonPath('data.schedules.0.expected_completion_date', '2026-11-04')
            ->assertJsonPath('data.technicians.0.name', 'Sam Fixer')
            ->assertJsonPath('data.technicians.0.phone', '614-555-0100')
            ->assertJsonPath('data.visits.0.purpose', 'work')
            ->assertJsonPath('data.workflow.next.0.action', 'work_done');

        $this->assertNotNull($response->json('trip_link.url_token'));
        $this->assertSame(Trip::query()->sole()->id, $response->json('data.schedules.0.trip_id'));

        // The same technician, the same day: the same trip, no new link.
        $this->schedule([], TicketIssue::factory()->for($this->ticket)->create())->assertOk()->assertJsonMissingPath('trip_link');
        $this->assertSame(1, Trip::query()->count());
    }

    public function test_a_new_issue_can_be_scheduled_straight_away(): void
    {
        $this->schedule()->assertOk()->assertJsonPath('data.status', 'scheduled');
    }

    public function test_scheduling_is_refused_while_waiting_on_the_store(): void
    {
        $this->putStatus(IssueStatus::WaitingOnStore);

        $this->assertRefused($this->schedule())
            ->assertJsonPath('errors.status.0', "We are still waiting for the store to answer. Record the store's answer first.");
        $this->assertSame(0, Assignment::query()->count());
    }

    public function test_moving_a_booking_keeps_the_old_date_and_moves_the_trip_stop(): void
    {
        $this->schedule()->assertOk();
        $assignment = Assignment::query()->sole();
        $oldTrip = Trip::query()->sole();

        $response = $this->act("schedules/{$assignment->id}/move", [
            'date' => '2026-11-06',
            'time' => '10:15',
            'reason' => 'Part arrives Friday',
        ])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.schedules.0.date', '2026-11-06')
            ->assertJsonPath('data.schedules.0.time', '10:15')
            ->assertJsonPath('data.schedules.0.delays.0.old_date', '2026-11-03')
            ->assertJsonPath('data.schedules.0.delays.0.old_time', '08:00')
            ->assertJsonPath('data.schedules.0.delays.0.new_date', '2026-11-06')
            ->assertJsonPath('data.schedules.0.delays.0.reason', 'Part arrives Friday')
            ->assertJsonCount(1, 'data.visits');

        $newTrip = Trip::query()->whereDate('planned_date', '2026-11-06')->sole();
        $this->assertNotSame($oldTrip->id, $newTrip->id);
        $this->assertSame($newTrip->id, $assignment->fresh()->trip_id);
        $this->assertSame($newTrip->id, $response->json('data.visits.0.trip_id'));
        $this->assertNotNull($response->json('trip_link.url_token'));

        // The old trip no longer sends him to the store for nothing.
        $this->assertSame(0, TripStop::query()->where('trip_id', $oldTrip->id)->count());
        $this->assertSame(1, TripStopIssue::query()->count());
        $this->assertSame(1, AssignmentDelay::query()->count());
    }

    public function test_moving_only_the_hour_keeps_the_trip(): void
    {
        $this->schedule()->assertOk();
        $assignment = Assignment::query()->sole();

        $this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-03', 'time' => '13:00', 'reason' => 'Lunch rush'])
            ->assertOk()
            ->assertJsonMissingPath('trip_link')
            ->assertJsonPath('data.schedules.0.time', '13:00');

        $this->assertSame(1, Trip::query()->count());
    }

    public function test_moving_an_inspection_moves_the_inspection_too(): void
    {
        $this->act('inspections', ['technician_id' => $this->technician->id, 'scheduled_date' => '2026-11-03'])->assertOk();
        $assignment = Assignment::query()->sole();

        $this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-05', 'reason' => 'Tech sick'])->assertOk()
            ->assertJsonPath('data.inspections.0.scheduled_date', '2026-11-05')
            ->assertJsonPath('data.visits.0.purpose', 'inspection')
            ->assertJsonPath('data.visits.0.planned_date', '2026-11-05');

        $this->assertSame('2026-11-05', IssueInspection::query()->sole()->scheduled_date->toDateString());
    }

    public function test_moving_needs_a_reason_and_a_change(): void
    {
        $this->schedule()->assertOk();
        $assignment = Assignment::query()->sole();

        $this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-04'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->assertRefused($this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-03', 'time' => '08:00', 'reason' => 'x']), 'date');
    }

    public function test_moving_is_refused_on_a_closed_issue_and_for_a_mistaken_booking(): void
    {
        $this->schedule()->assertOk();
        $assignment = Assignment::query()->sole();

        $this->putStatus(IssueStatus::Closed);
        $this->assertRefused($this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-09', 'reason' => 'x']));

        $this->putStatus(IssueStatus::Scheduled);
        $assignment->forceFill(['mistaken' => true])->save();
        $this->assertRefused($this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-09', 'reason' => 'x']))
            ->assertJsonPath('errors.status.0', 'This booking was marked as entered by mistake, so it cannot be moved.');
    }

    public function test_a_booking_of_another_issue_is_not_found(): void
    {
        $this->schedule()->assertOk();
        $assignment = Assignment::query()->sole();
        $other = TicketIssue::factory()->for($this->ticket)->create(['status' => 'scheduled']);

        $this->act("schedules/{$assignment->id}/move", ['date' => '2026-11-09', 'reason' => 'x'], $other)->assertNotFound();
    }
}
