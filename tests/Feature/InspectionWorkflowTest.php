<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Assignment;
use App\Models\IssueInspection;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripStopIssue;
use App\Services\TripService;
use Illuminate\Http\UploadedFile;

/**
 * Booking an inspection and recording what it found (docs/MAINTENANCE-V2.md
 * §2). An inspection is booked like work: a kind=inspection booking and the
 * issue on the technician's trip for that day.
 */
class InspectionWorkflowTest extends IssueWorkflowTestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-11-03',
            'scheduled_time' => '09:30',
            'instructions' => 'Ask for Mo at the counter',
        ];
    }

    public function test_booking_an_inspection_creates_the_trip_with_its_link_once(): void
    {
        $this->putStatus(IssueStatus::InReview);

        $response = $this->act('inspections', $this->body())->assertOk()
            ->assertJsonPath('data.status', 'inspection')
            ->assertJsonPath('data.step', 5)
            ->assertJsonPath('data.whose_turn', 'technician')
            ->assertJsonPath('data.inspections.0.technician.name', 'Sam Fixer')
            ->assertJsonPath('data.inspections.0.status', 'scheduled')
            ->assertJsonPath('data.inspections.0.scheduled_date', '2026-11-03')
            ->assertJsonPath('data.inspections.0.scheduled_time', '09:30')
            ->assertJsonPath('data.schedules.0.kind', 'inspection')
            ->assertJsonPath('data.schedules.0.time', '09:30')
            ->assertJsonPath('data.technicians.0.id', $this->technician->id)
            ->assertJsonPath('data.visits.0.purpose', 'inspection')
            ->assertJsonPath('data.visits.0.store_number', '03795-00001')
            ->assertJsonPath('data.visits.0.task_status', 'pending')
            ->assertJsonPath('data.workflow.next.0.action', 'inspection_result');

        $token = $response->json('trip_link.url_token');
        $this->assertSame(40, strlen($token));
        $this->assertSame(TripService::DEFAULT_LINK_IDLE_HOURS, $response->json('trip_link.expires_after_hours'));

        $trip = Trip::query()->sole();
        $this->assertSame(hash('sha256', $token), $trip->token_hash);
        $this->assertSame($trip->id, $response->json('trip_link.trip_id'));
        $this->assertSame($trip->id, $response->json('data.schedules.0.trip_id'));
        $this->assertSame('Ask for Mo at the counter', $trip->instructions);
        $this->assertSame('09:30:00', Assignment::query()->sole()->assigned_hour);
    }

    public function test_a_second_inspection_the_same_day_with_the_same_technician_reuses_the_trip(): void
    {
        $this->act('inspections', $this->body())->assertOk()->assertJsonStructure(['trip_link']);

        $other = TicketIssue::factory()->for($this->ticket)->create();
        $second = $this->act('inspections', $this->body(['scheduled_time' => '11:00']), $other)->assertOk();

        $second->assertJsonMissingPath('trip_link');
        $this->assertSame(1, Trip::query()->count());
        $this->assertSame(1, Trip::query()->sole()->stops()->count());
        $this->assertSame(2, TripStopIssue::query()->count());

        // Another day is another trip, with its own link.
        $third = TicketIssue::factory()->for($this->ticket)->create();
        $this->act('inspections', $this->body(['scheduled_date' => '2026-11-04']), $third)->assertOk()
            ->assertJsonStructure(['trip_link' => ['url_token', 'expires_after_hours']]);
        $this->assertSame(2, Trip::query()->count());
    }

    public function test_a_second_opinion_can_be_booked_while_inspecting(): void
    {
        $this->act('inspections', $this->body())->assertOk();
        $other = Technician::factory()->create(['name' => 'Second Opinion Co']);

        $this->act('inspections', $this->body(['technician_id' => $other->id]))->assertOk()
            ->assertJsonCount(2, 'data.inspections')
            ->assertJsonCount(2, 'data.technicians')
            ->assertJsonPath('data.status', 'inspection');
    }

    public function test_booking_is_refused_while_waiting_for_approval(): void
    {
        $this->putStatus(IssueStatus::WaitingApproval);

        $this->assertRefused($this->act('inspections', $this->body()))
            ->assertJsonPath('errors.status.0', 'The quote is still waiting for approval. Record the approval first.');
        $this->assertSame(0, Trip::query()->count());
        $this->assertSame(0, IssueInspection::query()->count());
    }

    public function test_a_removed_technician_cannot_be_booked(): void
    {
        $this->technician->delete();

        $this->act('inspections', $this->body())->assertUnprocessable()->assertJsonValidationErrors(['technician_id']);
    }

    public function test_recording_the_result_marks_it_done_and_keeps_the_status(): void
    {
        $this->act('inspections', $this->body())->assertOk();
        $inspection = IssueInspection::query()->sole();

        $this->post($this->url("/inspections/{$inspection->id}/result"), [
            'findings' => 'Compressor relay burnt',
            'recommended_repair' => 'Replace relay',
            'parts_needed' => 'Relay R-12',
            'estimated_cost' => '450',
            'files' => [UploadedFile::fake()->image('relay.jpg')],
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'inspection')
            ->assertJsonPath('data.inspections.0.status', 'done')
            ->assertJsonPath('data.inspections.0.findings', 'Compressor relay burnt')
            ->assertJsonPath('data.inspections.0.estimated_cost', '450.00')
            ->assertJsonPath('data.inspections.0.attachments.0.original_name', 'relay.jpg')
            ->assertJsonPath('data.costs.estimated', '450.00')
            ->assertJsonPath('data.visits.0.task_status', 'done')
            ->assertJsonPath('data.workflow.next.0.action', 'approval_request')
            ->assertJsonPath('data.workflow.approval_level_for', ['amount' => '450.00', 'level' => 'manager', 'label' => 'Maintenance Manager']);

        $this->assertNotNull($inspection->fresh()->done_at);
    }

    public function test_a_result_cannot_be_recorded_twice(): void
    {
        $this->act('inspections', $this->body())->assertOk();
        $inspection = IssueInspection::query()->sole();
        $this->act("inspections/{$inspection->id}/result", ['findings' => 'ok'])->assertOk();

        $this->assertRefused($this->act("inspections/{$inspection->id}/result", ['findings' => 'again']))
            ->assertJsonPath('errors.status.0', 'This inspection is already done.');
    }

    public function test_a_result_is_refused_on_a_closed_issue(): void
    {
        $this->act('inspections', $this->body())->assertOk();
        $inspection = IssueInspection::query()->sole();
        $this->putStatus(IssueStatus::Closed);

        $this->assertRefused($this->act("inspections/{$inspection->id}/result", ['findings' => 'late']));
    }
}
