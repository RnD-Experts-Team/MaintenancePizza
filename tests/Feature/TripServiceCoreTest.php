<?php

namespace Tests\Feature;

use App\Enums\TripEventKind;
use App\Enums\TripStatus;
use App\Models\MaintenanceSetting;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripSession;
use App\Models\TripStop;
use App\Models\TripStopIssue;
use App\Models\User;
use App\Services\TripService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The parts of TripService the workflow and payments call: the link, putting
 * an issue on a trip, and what a session's minutes add up to.
 */
class TripServiceCoreTest extends TestCase
{
    use RefreshDatabase;

    private TripService $trips;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trips = app(TripService::class);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Link

    public function test_a_link_is_forty_url_safe_characters_and_only_its_hash_is_stored(): void
    {
        $issue = TicketIssue::factory()->create();
        $result = $this->schedule($issue, Technician::factory()->create());

        $token = $result['token'];
        $this->assertNotNull($token);
        $this->assertSame(40, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);

        $trip = $result['trip']->fresh();
        $this->assertSame(hash('sha256', $token), $trip->token_hash);
        $this->assertStringNotContainsString($token, json_encode($trip->toArray()));
        $this->assertTrue($trip->is($this->trips->findByToken($token)));
        $this->assertNull($this->trips->findByToken('not-a-token'));
        $this->assertNull($this->trips->findByToken(''));
    }

    public function test_issuing_a_link_rotates_it_and_kills_the_old_one(): void
    {
        $trip = $this->schedule(TicketIssue::factory()->create(), Technician::factory()->create());
        $old = $trip['token'];

        $new = $this->trips->issueLink($trip['trip']);

        $this->assertNotSame($old, $new);
        $this->assertNull($this->trips->findByToken($old));
        $this->assertTrue($trip['trip']->is($this->trips->findByToken($new)));
    }

    public function test_a_link_dies_after_its_idle_hours_and_use_keeps_it_alive(): void
    {
        Carbon::setTestNow('2026-11-02 08:00:00');
        $trip = $this->schedule(TicketIssue::factory()->create(), Technician::factory()->create())['trip']->fresh();

        $this->assertSame(48, $trip->link_idle_hours);
        $this->assertTrue($this->trips->linkActive($trip));

        Carbon::setTestNow('2026-11-04 07:59:00');
        $this->assertTrue($this->trips->linkActive($trip));

        Carbon::setTestNow('2026-11-04 08:01:00');
        $this->assertFalse($this->trips->linkActive($trip));

        // Used again (here: re-issued), it lives another 48 hours from now.
        $this->trips->issueLink($trip);
        $this->assertTrue($this->trips->linkActive($trip->fresh()));

        Carbon::setTestNow('2026-11-05 08:00:00');
        $this->trips->markUsed($trip);
        Carbon::setTestNow('2026-11-07 07:00:00');
        $this->assertTrue($this->trips->linkActive($trip->fresh()));
    }

    public function test_a_closed_or_mistaken_trip_has_no_working_link(): void
    {
        $trip = $this->schedule(TicketIssue::factory()->create(), Technician::factory()->create())['trip'];

        $trip->update(['status' => TripStatus::Closed]);
        $this->assertFalse($this->trips->linkActive($trip->fresh()));

        $trip->update(['status' => TripStatus::Open, 'mistaken' => true]);
        $this->assertFalse($this->trips->linkActive($trip->fresh()));
    }

    public function test_a_new_trip_takes_its_idle_hours_from_the_settings(): void
    {
        MaintenanceSetting::put('trip_link_idle_hours', 12, $this->user->id);

        $trip = $this->schedule(TicketIssue::factory()->create(), Technician::factory()->create())['trip'];

        $this->assertSame(12, $trip->fresh()->link_idle_hours);
        $this->assertSame(12, MaintenanceSetting::get('trip_link_idle_hours'));
        $this->assertSame('fallback', MaintenanceSetting::get('nothing_here', 'fallback'));
    }

    // ------------------------------------------------------------ Scheduling

    public function test_scheduling_creates_a_trip_with_a_stop_for_the_store_and_the_issue_on_it(): void
    {
        $technician = Technician::factory()->create();
        $issue = TicketIssue::factory()->create();

        $result = $this->schedule($issue, $technician, '2026-11-03', 'inspection', 'Bring a ladder');

        $trip = $result['trip'];
        $this->assertSame($technician->id, $trip->technician_id);
        $this->assertSame(TripStatus::Open, $trip->status);
        $this->assertSame('2026-11-03', $trip->planned_date->toDateString());
        $this->assertSame('Bring a ladder', $trip->instructions);
        $this->assertSame($this->user->id, $trip->created_by);

        $stop = $result['stop'];
        $this->assertSame($trip->id, $stop->trip_id);
        $this->assertSame($issue->ticket->store_id, $stop->store_id);
        $this->assertSame('planned', $stop->status);

        $row = TripStopIssue::query()->sole();
        $this->assertSame($stop->id, $row->trip_stop_id);
        $this->assertSame($issue->id, $row->ticket_issue_id);
        $this->assertSame('inspection', $row->purpose);
        $this->assertSame('pending', $row->task_status);
    }

    public function test_a_second_issue_the_same_day_joins_the_open_trip_without_a_new_link(): void
    {
        $technician = Technician::factory()->create();
        $first = TicketIssue::factory()->create();
        $sameStore = TicketIssue::factory()->create(['ticket_id' => $first->ticket_id]);
        $otherStore = TicketIssue::factory()->create();

        $a = $this->schedule($first, $technician, '2026-11-03', 'work', 'First');
        $b = $this->schedule($sameStore, $technician, '2026-11-03', 'work', 'Second');
        $c = $this->schedule($otherStore, $technician, '2026-11-03');

        $this->assertNotNull($a['token']);
        $this->assertNull($b['token']);
        $this->assertNull($c['token']);
        $this->assertSame(1, Trip::query()->count());
        $this->assertTrue($a['trip']->is($b['trip']));
        $this->assertSame("First\n\nSecond", $b['trip']->instructions);

        // Same store, same stop; another store, a second stop after it.
        $this->assertTrue($a['stop']->is($b['stop']));
        $this->assertFalse($a['stop']->is($c['stop']));
        $this->assertSame(2, TripStop::query()->count());
        $this->assertGreaterThan($a['stop']->sort_order, $c['stop']->sort_order);
        $this->assertSame(3, TripStopIssue::query()->count());

        // The old link still works: joining a trip never rotates it.
        $this->assertTrue($a['trip']->is($this->trips->findByToken($a['token'])));
    }

    public function test_another_day_a_closed_trip_or_another_technician_means_a_new_trip(): void
    {
        $technician = Technician::factory()->create();
        $issue = TicketIssue::factory()->create();

        $monday = $this->schedule($issue, $technician, '2026-11-02')['trip'];
        $tuesday = $this->schedule($issue, $technician, '2026-11-03')['trip'];
        $this->assertFalse($monday->is($tuesday));

        $monday->update(['status' => TripStatus::Closed]);
        $again = $this->schedule($issue, $technician, '2026-11-02');
        $this->assertFalse($monday->is($again['trip']));
        $this->assertNotNull($again['token']);

        $other = $this->schedule($issue, Technician::factory()->create(), '2026-11-02')['trip'];
        $this->assertFalse($again['trip']->is($other));
        $this->assertSame(4, Trip::query()->count());
    }

    public function test_an_other_store_ticket_gets_a_stop_named_by_its_text(): void
    {
        $technician = Technician::factory()->create();
        $ticket = Ticket::factory()->otherStore('Warehouse')->create();
        $issue = TicketIssue::factory()->create(['ticket_id' => $ticket->id]);
        $second = TicketIssue::factory()->create(['ticket_id' => $ticket->id]);

        $a = $this->schedule($issue, $technician);
        $b = $this->schedule($second, $technician);

        $this->assertNull($a['stop']->store_id);
        $this->assertSame('Warehouse', $a['stop']->other_store);
        $this->assertTrue($a['stop']->is($b['stop']));
    }

    public function test_scheduling_the_same_issue_again_updates_its_purpose_and_restarts_the_task(): void
    {
        $technician = Technician::factory()->create();
        $issue = TicketIssue::factory()->create();

        $this->schedule($issue, $technician, '2026-11-03', 'inspection');
        TripStopIssue::query()->update(['task_status' => 'done', 'done_at' => now()]);

        // Same purpose again: the finished task is left alone.
        $this->schedule($issue, $technician, '2026-11-03', 'inspection');
        $this->assertSame('done', TripStopIssue::query()->sole()->task_status);

        // Now the work: one row, new purpose, pending again.
        $this->schedule($issue, $technician, '2026-11-03', 'work');
        $row = TripStopIssue::query()->sole();
        $this->assertSame('work', $row->purpose);
        $this->assertSame('pending', $row->task_status);
        $this->assertNull($row->done_at);
    }

    // ------------------------------------------------------------- Durations

    public function test_paid_time_is_the_clock_span_minus_breaks_and_work_is_what_is_left(): void
    {
        $session = $this->makeSession('2026-11-02 08:00', '2026-11-02 16:00', [
            ['travel_start', '08:00'], ['travel_end', '08:30'],
            ['break_start', '12:00'], ['break_end', '12:45'],
            ['parts_run_start', '13:00'], ['parts_run_end', '13:20'],
            ['travel_start', '15:30'], ['travel_end', '16:00'],
        ]);

        $this->assertSame([
            'work' => 480 - 45 - 60 - 20,
            'travel' => 60,
            'parts_run' => 20,
            'break' => 45,
            'paid' => 480 - 45,
        ], $this->trips->durations($session));
        $this->assertSame(435, $this->trips->paidMinutes($session));
    }

    public function test_an_open_session_and_an_open_span_count_up_to_now(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');

        $session = $this->makeSession('2026-11-02 08:00', null, [
            ['break_start', '09:30'],
        ]);

        $d = $this->trips->durations($session);
        $this->assertSame(30, $d['break']);
        $this->assertSame(90, $d['paid']);
        $this->assertSame(90, $d['work']);
    }

    public function test_an_unclosed_span_in_a_closed_session_runs_to_the_clock_out(): void
    {
        $session = $this->makeSession('2026-11-02 08:00', '2026-11-02 10:00', [
            ['travel_start', '09:00'],
        ]);

        $this->assertSame(['work' => 60, 'travel' => 60, 'parts_run' => 0, 'break' => 0, 'paid' => 120], $this->trips->durations($session));
    }

    public function test_mistaken_events_orphan_ends_and_repeated_starts_are_ignored(): void
    {
        $session = $this->makeSession('2026-11-02 08:00', '2026-11-02 12:00', [
            ['break_end', '08:10'],                    // an end with no start
            ['break_start', '09:00'],
            ['break_start', '09:30'],                  // a second start while on break
            ['break_end', '10:00'],
            ['travel_start', '10:30', true],           // mistaken
            ['travel_end', '11:00', true],
        ]);

        $d = $this->trips->durations($session);
        $this->assertSame(60, $d['break']);
        $this->assertSame(0, $d['travel']);
        $this->assertSame(180, $d['paid']);
    }

    public function test_events_outside_the_session_are_clipped_and_overlaps_count_once(): void
    {
        $session = $this->makeSession('2026-11-02 08:00', '2026-11-02 12:00', [
            ['travel_start', '07:00'],                 // before clock-in
            ['travel_end', '08:30'],
            ['break_start', '10:00'],
            ['travel_start', '10:30'],                 // driving "during" the break
            ['travel_end', '11:30'],
            ['break_end', '11:00'],
        ]);

        $d = $this->trips->durations($session);
        $this->assertSame(60, $d['break']);            // 10:00-11:00
        $this->assertSame(30 + 30, $d['travel']);      // 08:00-08:30 and 11:00-11:30
        $this->assertSame(180, $d['paid']);
        $this->assertSame($d['paid'], $d['work'] + $d['travel'] + $d['parts_run']);
    }

    public function test_the_database_lets_a_technician_be_clocked_in_only_once(): void
    {
        $technician = Technician::factory()->create();
        $this->makeSession('2026-11-02 08:00', null, [], $technician);
        $this->makeSession('2026-11-01 08:00', '2026-11-01 09:00', [], $technician);   // closed: fine

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeSession('2026-11-02 09:00', null, [], $technician);
    }

    // --------------------------------------------------------------- Helpers

    /**
     * @return array{trip: Trip, stop: TripStop, token: string|null}
     */
    private function schedule(TicketIssue $issue, Technician $technician, string $date = '2026-11-03', string $purpose = 'work', ?string $instructions = null): array
    {
        return $this->trips->scheduleIssue($issue, $technician, $date, null, $purpose, $instructions, $this->user);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: bool}>  $events  [kind, HH:MM (same day as clock-in), mistaken?]
     */
    private function makeSession(string $clockIn, ?string $clockOut, array $events, ?Technician $technician = null): TripSession
    {
        $technician ??= Technician::factory()->create();
        $trip = Trip::query()->create([
            'technician_id' => $technician->id,
            'status' => TripStatus::Open,
            'planned_date' => substr($clockIn, 0, 10),
        ]);

        $session = TripSession::query()->create([
            'trip_id' => $trip->id,
            'technician_id' => $technician->id,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);

        $day = substr($clockIn, 0, 10);
        foreach ($events as $event) {
            $session->events()->create([
                'kind' => TripEventKind::from($event[0]),
                'at' => "{$day} {$event[1]}",
                'mistaken' => $event[2] ?? false,
            ]);
        }

        return $session->fresh();
    }
}
