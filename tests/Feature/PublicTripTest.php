<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\IssueInspection;
use App\Models\MaintenanceOutboxEvent;
use App\Models\Part;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripSession;
use App\Models\User;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The technician's trip link (docs/MAINTENANCE.md §3 "Public endpoints"),
 * over HTTP and without any dashboard login: what opens it, what kills it,
 * the clock and its events, and doing the jobs on a stop.
 */
class PublicTripTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Technician $technician;

    private TicketIssue $issue;

    private Trip $trip;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2026-11-02 08:00:00');

        $this->technician = Technician::factory()->create(['name' => 'Sam Rivera']);
        $this->issue = TicketIssue::factory()->create(['status' => IssueStatus::Scheduled->value, 'other_title' => 'Fryer leaking']);
        ['trip' => $this->trip, 'token' => $this->token] = $this->schedule($this->issue);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ The link

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->getJson('/api/public/trips/'.str_repeat('a', 40))->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_the_link_expires_after_its_idle_hours_and_each_use_keeps_it_alive(): void
    {
        Carbon::setTestNow('2026-11-04 07:59:00');            // 47h59 after it was issued
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.state', 'off_clock');
        $this->assertTrue($this->trip->fresh()->last_used_at->eq(now()));

        Carbon::setTestNow('2026-11-06 07:58:00');            // 47h59 after that use
        $this->getJson($this->url())->assertOk();

        Carbon::setTestNow('2026-11-08 08:00:00');            // 48h02 idle
        $this->getJson($this->url())->assertStatus(410)->assertJsonPath('message', fn ($m) => str_contains($m, 'expired'));
        $this->postJson($this->url('clock-in'))->assertStatus(410);
        $this->assertSame(0, TripSession::query()->count());
    }

    public function test_a_closed_or_mistaken_trip_is_410(): void
    {
        $this->trip->update(['status' => 'closed']);
        $this->getJson($this->url())->assertStatus(410)->assertJsonPath('message', fn ($m) => str_contains($m, 'closed'));

        $this->trip->update(['status' => 'open', 'mistaken' => true]);
        $this->getJson($this->url())->assertStatus(410)->assertJsonPath('message', fn ($m) => str_contains($m, 'cancelled'));
    }

    public function test_the_page_shows_the_trip_without_audits_or_private_notes(): void
    {
        $this->trip->notes()->forceCreate(['body' => 'Do not rehire', 'is_private' => true]);
        $this->trip->notes()->forceCreate(['body' => 'Back door code 1234']);

        $data = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame($this->trip->id, $data['id']);
        $this->assertSame('off_clock', $data['state']);
        $this->assertArrayNotHasKey('audits', $data);
        $this->assertSame(['Back door code 1234'], array_column($data['notes'], 'body'));
        $this->assertSame('Fryer leaking', $data['stops'][0]['issues'][0]['title']);
        $this->assertSame('work', $data['stops'][0]['issues'][0]['purpose']);
    }

    // ------------------------------------------------------------- Clock

    public function test_he_can_be_clocked_in_only_once_across_two_trips(): void
    {
        ['token' => $second] = $this->schedule(TicketIssue::factory()->create(), '2026-11-03');

        $this->postJson($this->url('clock-in'), ['using_car' => true, 'odometer' => 1200])
            ->assertCreated()->assertJsonPath('data.state', 'on_clock')->assertJsonPath('data.on_clock', true);

        $this->postJson("/api/public/trips/{$second}/clock-in")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson("/api/public/trips/{$second}")->assertJsonPath('data.clocked_in_elsewhere', true);

        // Not even twice on the same trip.
        $this->postJson($this->url('clock-in'))->assertUnprocessable();
        $this->assertSame(1, TripSession::query()->count());

        $session = TripSession::query()->sole();
        $this->assertTrue($session->using_car);
        $this->assertSame(1200, $session->odometer_in);
        $this->assertSame('technician', $session->source);
    }

    public function test_clocking_out_and_back_in_the_next_day_makes_two_sessions_of_one_trip(): void
    {
        $this->postJson($this->url('clock-in'), [
            'using_car' => true,
            'odometer' => 1000,
            'photo' => UploadedFile::fake()->image('odometer.jpg'),
        ])->assertCreated();

        Carbon::setTestNow('2026-11-02 17:00:00');
        $this->postJson($this->url('clock-out'), ['odometer' => 1030])->assertOk()->assertJsonPath('data.state', 'off_clock');

        Carbon::setTestNow('2026-11-03 07:30:00');
        $this->postJson($this->url('clock-in'))->assertCreated();
        Carbon::setTestNow('2026-11-03 09:30:00');
        $data = $this->postJson($this->url('clock-out'))->assertOk()->json('data');

        $this->assertCount(2, $data['sessions']);
        $this->assertSame(540, $data['sessions'][0]['durations']['paid']);
        $this->assertSame(30, $data['sessions'][0]['miles']);
        $this->assertSame('odometer.jpg', $data['sessions'][0]['attachments'][0]['original_name']);
        $this->assertSame(120, $data['sessions'][1]['durations']['paid']);
        $this->assertSame(660, $data['totals']['paid_minutes']);
        $this->assertSame(1, Trip::query()->count());
        $this->assertSame(2, TripSession::query()->where('trip_id', $this->trip->id)->count());
    }

    public function test_events_must_make_sense(): void
    {
        // Off the clock: nothing to start.
        $this->postJson($this->url('events'), ['kind' => 'break_start'])->assertUnprocessable()->assertJsonValidationErrors('kind');

        $this->postJson($this->url('clock-in'))->assertCreated();

        $this->postJson($this->url('events'), ['kind' => 'break_end'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind' => 'You have not started your break.']);

        $this->postJson($this->url('events'), ['kind' => 'travel_start'])->assertCreated()->assertJsonPath('data.state', 'travelling');
        $this->postJson($this->url('events'), ['kind' => 'break_start'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind' => 'End driving first.']);
        $this->postJson($this->url('events'), ['kind' => 'travel_start'])->assertUnprocessable();

        Carbon::setTestNow('2026-11-02 08:30:00');
        $this->postJson($this->url('events'), ['kind' => 'travel_end'])->assertCreated()->assertJsonPath('data.state', 'on_clock');
        $this->postJson($this->url('events'), ['kind' => 'break_start'])->assertCreated()->assertJsonPath('data.state', 'on_break');
        $this->postJson($this->url('events'), ['kind' => 'nap_start'])->assertUnprocessable();

        // Clocking out ends the break at the same moment.
        Carbon::setTestNow('2026-11-02 09:00:00');
        $session = $this->postJson($this->url('clock-out'))->assertOk()->json('data.sessions.0');

        $this->assertSame(['break_end'], array_slice(array_column($session['events'], 'kind'), -1));
        $this->assertSame(['work' => 0, 'travel' => 30, 'parts_run' => 0, 'break' => 30, 'paid' => 30], $session['durations']);
    }

    // ------------------------------------------------------------ Stops

    public function test_arriving_and_leaving_a_stop_with_the_odometer_photo(): void
    {
        $stop = $this->trip->stops()->sole();

        $this->postJson($this->url("stops/{$stop->id}/leave"))->assertUnprocessable();

        $data = $this->postJson($this->url("stops/{$stop->id}/arrive"), [
            'odometer' => 1015,
            'photo' => UploadedFile::fake()->image('dash.jpg'),
        ])->assertOk()->json('data.stops.0');

        $this->assertSame('arrived', $data['status']);
        $this->assertSame(1015, $data['arrival_odometer']);
        $this->assertSame('dash.jpg', $data['attachments'][0]['original_name']);

        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->postJson($this->url("stops/{$stop->id}/leave"))->assertOk()->assertJsonPath('data.stops.0.status', 'done');
    }

    public function test_a_stop_of_another_trip_is_not_found(): void
    {
        ['trip' => $other] = $this->schedule(TicketIssue::factory()->create(), '2026-11-05', Technician::factory()->create());
        $theirStop = $other->stops()->sole();

        $this->postJson($this->url("stops/{$theirStop->id}/arrive"))->assertNotFound();

        // Nor an issue that is not on this stop.
        $stop = $this->trip->stops()->sole();
        $foreign = TicketIssue::factory()->create();
        $this->postJson($this->url("stops/{$stop->id}/issues/{$foreign->id}/done"), ['note' => 'x'])->assertNotFound();
    }

    public function test_he_can_add_a_visit_to_a_store_he_covers_but_not_to_any_store(): void
    {
        $covered = Store::factory()->create();
        $this->technician->coverageStores()->attach($covered->id);
        $random = Store::factory()->create();

        $stores = $this->getJson($this->url())->json('data.stores');
        $this->assertContains($covered->id, array_column($stores, 'id'));

        $this->postJson($this->url('stops'), ['store_id' => $random->id])->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->postJson($this->url('stops'), ['store_id' => $covered->id])->assertCreated()->assertJsonCount(2, 'data.stops');
        $this->postJson($this->url('stops'), ['store_id' => $covered->id])->assertCreated()->assertJsonCount(2, 'data.stops');
    }

    // ------------------------------------------------------------- Jobs

    public function test_ticking_a_work_job_done_moves_the_issue_to_work_done_and_publishes_it(): void
    {
        $stop = $this->trip->stops()->sole();

        $this->postJson($this->url("stops/{$stop->id}/issues/{$this->issue->id}/done"), [
            'note' => 'Replaced the drain valve gasket',
            'files' => [UploadedFile::fake()->image('after.jpg')],
        ])->assertOk()
            ->assertJsonPath('data.stops.0.issues.0.task_status', 'done')
            ->assertJsonPath('data.stops.0.issues.0.status', 'work_done');

        $issue = $this->issue->fresh();
        $this->assertSame(IssueStatus::WorkDone, $issue->status);
        $this->assertSame('Replaced the drain valve gasket', $issue->work_performed);
        $this->assertSame('after.jpg', $issue->attachments()->sole()->original_name);

        $change = $issue->statusChanges()->sole();
        $this->assertSame(IssueStatus::Scheduled, $change->from_status);
        $this->assertSame(IssueStatus::WorkDone, $change->to_status);

        $event = MaintenanceOutboxEvent::query()->where('subject', 'maintenance.issue.work_done')->sole();
        $this->assertSame($this->issue->id, $event->payload['data']['issue']['id']);
        $this->assertSame('Sam Rivera', $event->payload['data']['technician']['name']);
        $this->assertSame($this->trip->id, $event->payload['data']['trip_id']);

        // Twice is refused.
        $this->postJson($this->url("stops/{$stop->id}/issues/{$this->issue->id}/done"), ['note' => 'again'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, MaintenanceOutboxEvent::query()->where('subject', 'maintenance.issue.work_done')->count());
    }

    public function test_before_and_after_photos_are_kept_on_the_issue_and_read_back_as_such(): void
    {
        $stop = $this->trip->stops()->sole();

        $this->post($this->url("stops/{$stop->id}/issues/{$this->issue->id}/done"), [
            'note' => 'Replaced the drain valve gasket',
            'files' => [UploadedFile::fake()->image('receipt.jpg')],
            'before' => [UploadedFile::fake()->image('drain.jpg'), UploadedFile::fake()->image('leak.jpg')],
            'after' => [UploadedFile::fake()->image('drain.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $names = $this->issue->attachments()->orderBy('id')->pluck('original_name')->all();
        $this->assertSame(['receipt.jpg', 'Before - drain.jpg', 'Before - leak.jpg', 'After - drain.jpg'], $names);

        // The dashboard's issue read says which is which.
        $this->fakeAuthServer();
        $ticket = $this->issue->ticket;
        $attachments = $this->getJson("/api/stores/{$ticket->store->store_number}/tickets/{$ticket->id}/issues/{$this->issue->id}", $this->headers())
            ->assertOk()
            ->json('data.attachments');
        $this->assertSame(
            ['receipt.jpg' => null, 'Before - drain.jpg' => 'before', 'Before - leak.jpg' => 'before', 'After - drain.jpg' => 'after'],
            array_column($attachments, 'kind', 'original_name'),
        );

        // Only files.
        $other = TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Scheduled]);
        $this->app->make(TripService::class)->attachIssue($stop, $other, 'work');
        $this->postJson($this->url("stops/{$stop->id}/issues/{$other->id}/done"), [
            'note' => 'x',
            'before' => ['not a file'],
        ])->assertUnprocessable()->assertJsonValidationErrors('before.0');
    }

    public function test_an_inspection_done_fills_the_open_inspection_and_leaves_the_status(): void
    {
        $issue = TicketIssue::factory()->create(['status' => IssueStatus::Inspection->value]);
        $inspection = IssueInspection::query()->create([
            'ticket_issue_id' => $issue->id,
            'technician_id' => $this->technician->id,
            'status' => 'scheduled',
            'scheduled_date' => '2026-11-02',
        ]);
        ['trip' => $trip, 'token' => $token] = $this->schedule($issue, '2026-11-04', null, 'inspection');
        $stop = $trip->stops()->sole();
        $url = "/api/public/trips/{$token}/stops/{$stop->id}/issues/{$issue->id}/done";

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('findings');

        $this->postJson($url, ['findings' => 'Compressor is dead', 'estimated_cost' => 850.5])
            ->assertOk()->assertJsonPath('data.stops.0.issues.0.task_status', 'done');

        $inspection->refresh();
        $this->assertSame('done', $inspection->status->value);
        $this->assertSame('Compressor is dead', $inspection->findings);
        $this->assertSame('850.50', $inspection->estimated_cost);
        $this->assertNotNull($inspection->done_at);
        $this->assertSame(IssueStatus::Inspection, $issue->fresh()->status);
        $this->assertSame(0, MaintenanceOutboxEvent::query()->where('subject', 'maintenance.issue.work_done')->count());
    }

    public function test_a_job_not_done_keeps_its_reason(): void
    {
        $stop = $this->trip->stops()->sole();

        $this->postJson($this->url("stops/{$stop->id}/issues/{$this->issue->id}/not-done"), [])->assertUnprocessable();
        $this->postJson($this->url("stops/{$stop->id}/issues/{$this->issue->id}/not-done"), ['reason' => 'Part on back order'])
            ->assertOk()->assertJsonPath('data.stops.0.issues.0.task_status', 'not_done')
            ->assertJsonPath('data.stops.0.issues.0.not_done_reason', 'Part on back order');

        $this->assertSame(IssueStatus::Scheduled, $this->issue->fresh()->status);

        $this->postJson($this->url("stops/{$stop->id}/not-done"), ['reason' => 'Store was closed'])
            ->assertOk()->assertJsonPath('data.stops.0.status', 'not_done');
    }

    // ------------------------------------------------------ Notes & lookups

    public function test_his_notes_are_never_private_and_can_go_on_a_stop(): void
    {
        $stop = $this->trip->stops()->sole();

        $this->postJson($this->url('notes'), ['body' => 'Running late', 'is_private' => true])->assertCreated()
            ->assertJsonPath('data.notes.0.body', 'Running late');
        $this->postJson($this->url('notes'), ['body' => 'Manager not in', 'stop_id' => $stop->id])->assertCreated()
            ->assertJsonPath('data.stops.0.notes.0.body', 'Manager not in');
        $this->postJson($this->url('notes'), ['body' => 'x', 'stop_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('stop_id');

        $this->assertFalse((bool) $this->trip->notes()->sole()->is_private);
    }

    public function test_supplier_and_part_lookups_return_id_and_name_only(): void
    {
        Supplier::query()->create(['name' => 'Home Depot', 'phone' => '555']);
        Supplier::query()->create(['name' => 'Walmart']);
        Part::factory()->create(['name' => 'Gasket 3in']);

        $this->getJson($this->url('suppliers?search=home'))->assertOk()->assertExactJson(['data' => [
            ['id' => Supplier::query()->where('name', 'Home Depot')->value('id'), 'name' => 'Home Depot'],
        ]]);
        $this->getJson($this->url('suppliers'))->assertJsonCount(2, 'data');
        $this->getJson($this->url('parts?search=gask'))->assertJsonPath('data.0.name', 'Gasket 3in');
    }

    // --------------------------------------------------------------- Helpers

    private function url(string $path = ''): string
    {
        return "/api/public/trips/{$this->token}".($path === '' ? '' : "/{$path}");
    }

    /**
     * @return array{trip: Trip, token: string}
     */
    private function schedule(TicketIssue $issue, string $date = '2026-11-02', ?Technician $technician = null, string $purpose = 'work'): array
    {
        $result = app(TripService::class)->scheduleIssue(
            $issue,
            $technician ?? $this->technician,
            $date,
            null,
            $purpose,
            null,
            User::factory()->create(),
        );

        return ['trip' => $result['trip'], 'token' => $result['token']];
    }
}
