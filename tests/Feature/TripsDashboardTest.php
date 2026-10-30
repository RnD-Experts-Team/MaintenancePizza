<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripAudit;
use App\Models\TripSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Trips from the dashboard (docs/MAINTENANCE-V2.md §3), over HTTP: a trip by
 * hand and its one-time link, the specialist's corrections (each audited),
 * durations as presented, and the sessions nobody has paid yet.
 */
class TripsDashboardTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Technician $technician;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00');
        Storage::fake('public');
        $this->fakeAuthServer();
        $this->technician = Technician::factory()->create(['name' => 'Sam Rivera', 'phone' => '555-0101']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creating_a_trip_returns_its_link_once_and_never_again(): void
    {
        $issue = TicketIssue::factory()->create();
        $store = $issue->ticket->store;

        $response = $this->postJson('/api/trips', [
            'technician_id' => $this->technician->id,
            'planned_date' => '2026-11-03',
            'instructions' => 'Key is at the counter',
            'stops' => [['store_id' => $store->id, 'ticket_issue_ids' => [$issue->id]]],
        ], $this->headers())->assertCreated();

        $token = $response->json('data.trip_link.url_token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertSame(48, $response->json('data.trip_link.expires_after_hours'));
        $this->assertSame('Sam Rivera', $response->json('data.technician.name'));
        $this->assertSame('2026-11-03', $response->json('data.planned_date'));
        $this->assertTrue($response->json('data.link.active'));
        $this->assertSame($store->store_number, $response->json('data.stops.0.store.store_number'));
        $this->assertSame($issue->id, $response->json('data.stops.0.issues.0.ticket_issue_id'));
        $this->assertSame('work', $response->json('data.stops.0.issues.0.purpose'));

        $id = $response->json('data.id');
        $show = $this->getJson("/api/trips/{$id}", $this->headers())->assertOk();
        $this->assertNull($show->json('data.trip_link'));
        $this->assertStringNotContainsString($token, $show->getContent());
        $this->assertStringNotContainsString(hash('sha256', $token), $show->getContent());
        $this->assertSame('created', $show->json('data.audits.0.action'));

        // The token opens the trip on the public side.
        $this->getJson("/api/public/trips/{$token}")->assertOk()->assertJsonPath('data.id', $id);

        // A new link kills the old one.
        $new = $this->postJson("/api/trips/{$id}/link", [], $this->headers())->assertOk()->json('data.trip_link.url_token');
        $this->assertNotSame($token, $new);
        $this->getJson("/api/public/trips/{$token}")->assertNotFound();
        $this->getJson("/api/public/trips/{$new}")->assertOk();
    }

    public function test_a_stop_refuses_issues_from_another_store(): void
    {
        $here = TicketIssue::factory()->create();
        $elsewhere = TicketIssue::factory()->create();

        $this->postJson('/api/trips', [
            'technician_id' => $this->technician->id,
            'planned_date' => '2026-11-03',
            'stops' => [['store_id' => $here->ticket->store_id, 'ticket_issue_ids' => [$here->id, $elsewhere->id]]],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('stops.0.ticket_issue_ids');

        $this->assertSame(0, Trip::query()->count());

        // Without a store, the stop goes where the issues are.
        $trip = $this->makeTrip();
        $this->postJson("/api/trips/{$trip['id']}/stops", ['ticket_issue_ids' => [$here->id], 'purpose' => 'inspection'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.stops.0.store.id', $here->ticket->store_id)
            ->assertJsonPath('data.stops.0.issues.0.purpose', 'inspection');
    }

    public function test_the_list_filters_by_technician_status_and_who_is_on_the_clock(): void
    {
        $mine = $this->makeTrip();
        $other = Technician::factory()->create(['name' => 'Ola Lind']);
        $theirs = $this->makeTrip($other);
        TripSession::query()->create(['trip_id' => $theirs['id'], 'technician_id' => $other->id, 'clock_in' => now()->subHour()]);
        Trip::query()->whereKey($mine['id'])->update(['status' => 'closed']);

        $this->getJson('/api/trips?technician_id='.$this->technician->id, $this->headers())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id'])
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/trips?on_clock=1', $this->headers())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $theirs['id'])
            ->assertJsonPath('data.0.on_clock', true);
        $this->getJson('/api/trips?status=open', $this->headers())->assertJsonCount(1, 'data');
        $this->getJson('/api/trips?search=ola', $this->headers())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $theirs['id']);
    }

    public function test_the_presented_trip_adds_up_durations_paid_minutes_and_miles(): void
    {
        $trip = $this->makeTrip();

        $this->postJson("/api/trips/{$trip['id']}/sessions", [
            'clock_in' => '2026-11-02 08:00:00',
            'clock_out' => '2026-11-02 16:00:00',
            'using_car' => true,
            'odometer_in' => 1000,
            'odometer_out' => 1042,
            'reason' => 'Clocked on paper',
        ], $this->headers())->assertCreated();
        $session = TripSession::query()->sole();

        foreach ([['travel_start', '08:00'], ['travel_end', '08:30'], ['break_start', '12:00'], ['break_end', '12:45']] as [$kind, $at]) {
            $this->postJson("/api/trips/{$trip['id']}/sessions/{$session->id}/events", [
                'kind' => $kind, 'at' => "2026-11-02 {$at}:00", 'reason' => 'From his paper sheet',
            ], $this->headers())->assertCreated();
        }

        $data = $this->getJson("/api/trips/{$trip['id']}", $this->headers())->assertOk()->json('data');

        $this->assertSame(['work' => 405, 'travel' => 30, 'parts_run' => 0, 'break' => 45, 'paid' => 435], $data['sessions'][0]['durations']);
        $this->assertSame('specialist', $data['sessions'][0]['source']);
        $this->assertSame(42, $data['sessions'][0]['miles']);
        $this->assertFalse($data['sessions'][0]['paid']);
        $this->assertCount(4, $data['sessions'][0]['events']);
        $this->assertSame('Break started', $data['sessions'][0]['events'][2]['label']);
        $this->assertSame(['paid_minutes' => 435, 'break_minutes' => 45, 'miles' => 42], $data['totals']);
        $this->assertFalse($data['on_clock']);
    }

    public function test_editing_a_session_writes_an_audit_with_before_after_reason_and_user(): void
    {
        $trip = $this->makeTrip();
        $session = TripSession::query()->create([
            'trip_id' => $trip['id'],
            'technician_id' => $this->technician->id,
            'clock_in' => '2026-11-02 08:00:00',
            'clock_out' => '2026-11-02 12:00:00',
        ]);

        $this->patchJson("/api/trips/{$trip['id']}/sessions/{$session->id}", [
            'clock_out' => '2026-11-02 13:30:00',
            'reason' => 'He forgot to clock out',
        ], $this->headers())->assertOk()->assertJsonPath('data.sessions.0.durations.paid', 330);

        $audit = TripAudit::query()->where('subject_type', 'session')->where('action', 'updated')->sole();
        $this->assertSame($session->id, $audit->subject_id);
        $this->assertSame('He forgot to clock out', $audit->reason);
        $this->assertSame($this->authUser->id, $audit->created_by);
        $this->assertStringStartsWith('2026-11-02T12:00:00', $audit->before['clock_out']);
        $this->assertStringStartsWith('2026-11-02T13:30:00', $audit->after['clock_out']);

        $audits = $this->getJson("/api/trips/{$trip['id']}", $this->headers())->json('data.audits');
        $this->assertSame('Dana Whitfield', collect($audits)->firstWhere('action', 'updated')['user']['name']);

        // A reason is required.
        $this->patchJson("/api/trips/{$trip['id']}/sessions/{$session->id}", ['clock_out' => '2026-11-02 14:00:00'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_typed_in_sessions_cannot_overlap_or_end_before_they_start(): void
    {
        $trip = $this->makeTrip();
        $body = ['clock_in' => '2026-11-02 08:00:00', 'clock_out' => '2026-11-02 12:00:00', 'reason' => 'Paper'];

        $this->postJson("/api/trips/{$trip['id']}/sessions", $body, $this->headers())->assertCreated();
        $this->postJson("/api/trips/{$trip['id']}/sessions", ['clock_in' => '2026-11-02 11:00:00', 'clock_out' => '2026-11-02 13:00:00', 'reason' => 'Paper'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('clock_in');
        $this->postJson("/api/trips/{$trip['id']}/sessions", ['clock_in' => '2026-11-02 13:00:00', 'clock_out' => '2026-11-02 12:00:00', 'reason' => 'Paper'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('clock_out');

        $this->assertSame(1, TripSession::query()->count());
    }

    public function test_a_paid_session_is_frozen_and_events_must_fall_inside_their_session(): void
    {
        $trip = $this->makeTrip();
        $session = $this->closedSession($trip['id']);

        $this->postJson("/api/trips/{$trip['id']}/sessions/{$session->id}/events", [
            'kind' => 'break_start', 'at' => '2026-11-02 18:00:00', 'reason' => 'x',
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('at');

        $payment = $this->pay($session);

        $this->patchJson("/api/trips/{$trip['id']}/sessions/{$session->id}", ['clock_out' => '2026-11-02 17:00:00', 'reason' => 'x'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/trips/{$trip['id']}/mistaken", ['reason' => 'x'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $data = $this->getJson("/api/trips/{$trip['id']}", $this->headers())->json('data.sessions.0');
        $this->assertTrue($data['paid']);
        $this->assertSame($payment->id, $data['payment_id']);
    }

    public function test_a_session_of_another_trip_is_not_found_through_this_one(): void
    {
        $a = $this->makeTrip();
        $b = $this->makeTrip(Technician::factory()->create());
        $session = $this->closedSession($b['id'], Trip::query()->find($b['id'])->technician_id);

        $this->patchJson("/api/trips/{$a['id']}/sessions/{$session->id}", ['clock_out' => '2026-11-02 17:00:00', 'reason' => 'x'], $this->headers())
            ->assertNotFound();
    }

    public function test_marking_an_event_and_a_session_mistaken_is_audited_and_drops_them_from_the_totals(): void
    {
        $trip = $this->makeTrip();
        $session = $this->closedSession($trip['id']);
        $event = $session->events()->create(['kind' => 'break_start', 'at' => '2026-11-02 10:00:00']);
        $session->events()->create(['kind' => 'break_end', 'at' => '2026-11-02 11:00:00']);

        $this->assertSame(180, $this->getJson("/api/trips/{$trip['id']}", $this->headers())->json('data.totals.paid_minutes'));

        $this->postJson("/api/trips/{$trip['id']}/sessions/{$session->id}/events/{$event->id}/mistaken", ['reason' => 'No break that day'], $this->headers())
            ->assertOk()->assertJsonPath('data.totals.paid_minutes', 240);

        $this->postJson("/api/trips/{$trip['id']}/sessions/{$session->id}/mistaken", ['reason' => 'Wrong trip'], $this->headers())
            ->assertOk()->assertJsonPath('data.totals.paid_minutes', 0)->assertJsonPath('data.sessions.0.mistaken', true);

        $this->assertSame(['event', 'session'], TripAudit::query()->where('action', 'mistaken')->orderBy('id')->pluck('subject_type')->all());
    }

    public function test_closing_refuses_while_he_is_clocked_in_and_reopening_revives_the_link(): void
    {
        Carbon::setTestNow('2026-11-02 08:00:00');
        $trip = $this->makeTrip();
        $open = TripSession::query()->create(['trip_id' => $trip['id'], 'technician_id' => $this->technician->id, 'clock_in' => now()]);

        $this->postJson("/api/trips/{$trip['id']}/close", [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('status');

        $open->update(['clock_out' => now()->addHour()]);
        $this->postJson("/api/trips/{$trip['id']}/close", ['reason' => 'All done'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.link.active', false);
        $this->getJson("/api/public/trips/{$trip['token']}")->assertStatus(410);

        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->postJson("/api/trips/{$trip['id']}/reopen", [], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.link.active', true);
        $this->getJson("/api/public/trips/{$trip['token']}")->assertOk();
    }

    public function test_patching_the_trip_is_audited(): void
    {
        $trip = $this->makeTrip();

        $this->patchJson("/api/trips/{$trip['id']}", ['link_idle_hours' => 12, 'instructions' => 'Call first'], $this->headers())
            ->assertOk()->assertJsonPath('data.link.idle_hours', 12)->assertJsonPath('data.instructions', 'Call first');

        $audit = TripAudit::query()->where('action', 'updated')->sole();
        $this->assertSame(48, $audit->before['link_idle_hours']);
        $this->assertSame(12, $audit->after['link_idle_hours']);
    }

    public function test_unpaid_sessions_lists_closed_live_unclaimed_sessions_with_their_issues(): void
    {
        $issue = TicketIssue::factory()->create(['other_title' => 'Walk-in cooler']);
        $trip = $this->makeTrip(null, [['store_id' => $issue->ticket->store_id, 'ticket_issue_ids' => [$issue->id]]]);

        $unpaid = $this->closedSession($trip['id'], null, '2026-11-02');
        $paid = $this->closedSession($trip['id'], null, '2026-11-03');
        $released = $this->closedSession($trip['id'], null, '2026-11-04');
        $mistaken = $this->closedSession($trip['id'], null, '2026-11-05');
        $mistaken->update(['mistaken' => true]);
        TripSession::query()->create(['trip_id' => $trip['id'], 'technician_id' => $this->technician->id, 'clock_in' => now()]);   // still open

        $this->pay($paid);
        $this->pay($released, true);   // its payment was a mistake: unpaid again

        $otherTech = Technician::factory()->create();
        $other = $this->makeTrip($otherTech);
        $this->closedSession($other['id'], $otherTech->id, '2026-11-02');

        $rows = $this->getJson('/api/trip-sessions/unpaid?technician_id='.$this->technician->id, $this->headers())->assertOk()->json('data');

        $this->assertSame([$unpaid->id, $released->id], array_column($rows, 'id'));
        $this->assertSame(240, $rows[0]['paid_minutes']);
        $this->assertSame(240, $rows[0]['durations']['paid']);
        $this->assertSame('Sam Rivera', $rows[0]['technician']['name']);
        $this->assertSame('Walk-in cooler', $rows[0]['ticket_issues'][0]['title']);
        $this->assertSame($issue->ticket->store->store_number, $rows[0]['ticket_issues'][0]['store_number']);

        $this->assertCount(3, $this->getJson('/api/trip-sessions/unpaid', $this->headers())->json('data'));
    }

    public function test_private_trip_notes_show_on_the_dashboard_only(): void
    {
        $trip = $this->makeTrip();

        $this->postJson("/api/trips/{$trip['id']}/notes", ['body' => 'He overbills, check hours', 'is_private' => true], $this->headers())->assertCreated();
        $this->postJson("/api/trips/{$trip['id']}/notes", ['body' => 'Park at the back'], $this->headers())->assertCreated();

        $this->assertCount(2, $this->getJson("/api/trips/{$trip['id']}", $this->headers())->json('data.notes'));

        $public = $this->getJson("/api/public/trips/{$trip['token']}")->assertOk();
        $this->assertSame(['Park at the back'], array_column($public->json('data.notes'), 'body'));
        $this->assertStringNotContainsString('overbills', $public->getContent());
        $this->assertStringNotContainsString('dana@example.com', $public->getContent());
        $this->assertArrayNotHasKey('audits', $public->json('data'));
    }

    // --------------------------------------------------------------- Helpers

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array{id: int, token: string}
     */
    private function makeTrip(?Technician $technician = null, array $stops = []): array
    {
        $response = $this->postJson('/api/trips', [
            'technician_id' => ($technician ?? $this->technician)->id,
            'planned_date' => '2026-11-02',
            'stops' => $stops,
        ], $this->headers())->assertCreated();

        return ['id' => $response->json('data.id'), 'token' => $response->json('data.trip_link.url_token')];
    }

    private function closedSession(int $tripId, ?int $technicianId = null, string $day = '2026-11-02'): TripSession
    {
        return TripSession::query()->create([
            'trip_id' => $tripId,
            'technician_id' => $technicianId ?? $this->technician->id,
            'clock_in' => "{$day} 08:00:00",
            'clock_out' => "{$day} 12:00:00",
        ]);
    }

    private function pay(TripSession $session, bool $mistaken = false): Payment
    {
        $payment = Payment::query()->create([
            'paid_at' => '2026-11-06',
            'technician_id' => $session->technician_id,
            'total' => '100.00',
            'mistaken' => $mistaken,
        ]);
        $line = PaymentLine::query()->create([
            'payment_id' => $payment->id,
            'type' => 'hourly',
            'store_id' => Store::factory()->create()->id,
            'amount' => '100.00',
        ]);
        DB::table('payment_line_trip_session')->insert([
            'payment_line_id' => $line->id,
            'trip_session_id' => $session->id,
            'paid_minutes' => 240,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $payment;
    }
}
