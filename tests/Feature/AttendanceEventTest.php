<?php

namespace Tests\Feature;

use App\Models\AttendanceEntry;
use App\Models\DailyPayEntry;
use App\Models\DailyPayPayment;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\WorkflowRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attendance as an append-only event ledger.
 *
 * THE COMPLAINT THIS ANSWERS, in the words it arrived in: "I put down that he
 * clocked in, then I save, if I wanna do a travel start, I need to either
 * correct the old entry or make a new entry -- that is incorrect."
 *
 * It was correct as a description: after createAttendance() the only mutation
 * in the whole system was mistaken = true. There was no update path at all.
 * And the four fixed start/end column pairs meant one break per session, so a
 * second one needed a second entry that then read as a second visit.
 *
 * EVERY TEST GOES OVER HTTP, for the same reason StoragePlaceTest does: the
 * storage slots feature shipped with a route-binding bug its service-level
 * tests could not see, and these routes use the same scoped bindings.
 */
class AttendanceEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\AuthTokenStoreScopeMiddleware::class);
    }

    /**
     * A session with one clock-in on it, plus everything the URL needs.
     *
     * @return array{0: AttendanceEntry, 1: string}
     */
    private function openSession(string $clockIn = '2026-09-10 08:00:00'): array
    {
        $store = Store::factory()->create();
        $ticket = Ticket::factory()->for($store)->create();
        $issue = TicketIssue::factory()->for($ticket)->create();
        $technician = Technician::factory()->create();
        $technician->ticketIssues()->attach($issue->id);

        app(WorkflowRecordService::class)->createAttendance([
            'technician_id' => $technician->id,
            'ticket_issue_ids' => [$issue->id],
            'start_clock' => $clockIn,
        ], [], []);

        $entry = AttendanceEntry::sole();

        return [$entry, "/api/stores/{$store->store_number}/tickets/{$ticket->id}"];
    }

    /* -------------------------------------------------- the missing ability */

    /**
     * The whole point. Adding to a SAVED session is an insert, not a
     * correction and not a second record.
     */
    public function test_an_event_can_be_added_to_a_saved_session(): void
    {
        [$entry, $base] = $this->openSession();

        $data = $this->postJson("{$base}/attendance-entries/{$entry->id}/events", [
            'kind' => 'travel_start',
            'at' => '2026-09-10 08:30:00',
        ])->assertCreated()->json('data');

        $this->assertSame($entry->id, $data['id']);
        $this->assertSame(
            ['clock_in', 'travel_start'],
            array_column($data['events'], 'kind')
        );
        // Still one session. Nothing was corrected and nothing was duplicated.
        $this->assertSame(1, AttendanceEntry::count());
    }

    /** The thing four fixed column pairs made impossible. */
    public function test_a_session_can_hold_two_breaks(): void
    {
        [$entry, $base] = $this->openSession();

        foreach ([
            ['break_start', '2026-09-10 10:00:00'],
            ['break_end', '2026-09-10 10:15:00'],
            ['break_start', '2026-09-10 13:00:00'],
            ['break_end', '2026-09-10 13:30:00'],
            ['clock_out', '2026-09-10 16:00:00'],
        ] as [$kind, $at]) {
            $this->postJson("{$base}/attendance-entries/{$entry->id}/events", compact('kind', 'at'))
                ->assertCreated();
        }

        $data = $this->postJson("{$base}/attendance-entries/{$entry->id}/events", [
            'kind' => 'parts_run_start',
            'at' => '2026-09-10 14:00:00',
        ])->json('data');

        $this->assertSame(45, $data['durations']['minutes']['break']);
        $this->assertSame(1, AttendanceEntry::count());
    }

    /**
     * Coming back to a store later is a second visit, not a continuation -- and
     * a session with two clock-ins would make "when did this shift start"
     * unanswerable.
     */
    public function test_a_second_clock_in_opens_a_new_session(): void
    {
        [$entry, $base] = $this->openSession();

        $this->postJson("{$base}/attendance-entries/{$entry->id}/events", [
            'kind' => 'clock_out', 'at' => '2026-09-10 12:00:00',
        ])->assertCreated();

        $data = $this->postJson("{$base}/attendance-entries/{$entry->id}/events", [
            'kind' => 'clock_in', 'at' => '2026-09-10 16:00:00',
        ])->assertCreated()->json('data');

        $this->assertSame(2, AttendanceEntry::count());
        $this->assertNotSame($entry->id, $data['id']);
        // The new session inherits what made it the same piece of work.
        $this->assertSame($entry->technician_id, $data['technician_id']);
        $this->assertSame(
            $entry->ticketIssues()->pluck('ticket_issues.id')->all(),
            $data['ticket_issue_ids']
        );
    }

    /* ---------------------------------------------------------- corrections */

    public function test_an_events_time_can_be_corrected(): void
    {
        [$entry, $base] = $this->openSession();
        $event = $entry->events()->sole();

        $data = $this->patchJson(
            "{$base}/attendance-entries/{$entry->id}/events/{$event->id}",
            ['at' => '2026-09-10 07:45:00']
        )->assertOk()->json('data');

        $this->assertStringStartsWith('2026-09-10T07:45', $data['events'][0]['at']);
        // The cached clock window follows the events, in the same transaction.
        $this->assertSame('2026-09-10 07:45:00', $entry->fresh()->start_clock->toDateTimeString());
    }

    /**
     * You can fix what nobody has been paid against; you cannot quietly
     * rewrite what somebody was paid on.
     */
    public function test_correcting_a_paid_session_is_refused(): void
    {
        [$entry, $base] = $this->openSession();
        $event = $entry->events()->sole();

        // No factory for these -- daily pay entries are built through their own
        // service in every other test. The minimum row that makes the session
        // "claimed" is enough here; what is under test is the refusal.
        $payment = DailyPayPayment::create([
            'daily_pay_entry_id' => DailyPayEntry::create(['date' => '2026-09-10'])->id,
            'technician_id' => $entry->technician_id,
        ]);
        $entry->dailyPayPayments()->attach($payment->id, [
            'work_minutes' => 0, 'travel_minutes' => 0, 'break_minutes' => 0, 'parts_run_minutes' => 0,
        ]);

        $this->patchJson(
            "{$base}/attendance-entries/{$entry->id}/events/{$event->id}",
            ['at' => '2026-09-10 07:45:00']
        )->assertStatus(422)->assertJsonValidationErrors('at');

        $this->assertSame('2026-09-10 08:00:00', $entry->fresh()->start_clock->toDateTimeString());
    }

    /**
     * A struck event stays in the ledger and stops counting. Striking the
     * clock-in leaves the session with no opening, so the cached window goes
     * null rather than keeping a figure nothing supports.
     */
    public function test_an_event_can_be_flagged_mistaken(): void
    {
        [$entry, $base] = $this->openSession();
        $event = $entry->events()->sole();

        $data = $this->postJson(
            "{$base}/attendance-entries/{$entry->id}/events/{$event->id}/mistaken"
        )->assertOk()->json('data');

        $this->assertTrue($data['events'][0]['mistaken']);
        $this->assertNull($entry->fresh()->start_clock);
        $this->assertSame(0, $data['durations']['minutes']['work']);
    }

    /* ------------------------------------------------------------- the wiring */

    /** scopeBindings: one session's event must not be reachable through
     *  another session's URL. */
    public function test_an_event_cannot_be_reached_through_another_sessions_url(): void
    {
        [$entry, $base] = $this->openSession();
        $event = $entry->events()->sole();

        $other = AttendanceEntry::create(['technician_id' => $entry->technician_id]);

        $this->patchJson(
            "{$base}/attendance-entries/{$other->id}/events/{$event->id}",
            ['at' => '2026-09-10 07:45:00']
        )->assertNotFound();

        $this->assertSame('2026-09-10 08:00:00', $entry->fresh()->start_clock->toDateTimeString());
    }

    public function test_an_event_needs_a_time(): void
    {
        [$entry, $base] = $this->openSession();

        $this->postJson("{$base}/attendance-entries/{$entry->id}/events", ['kind' => 'clock_out'])
            ->assertStatus(422)->assertJsonValidationErrors('at');
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        [$entry, $base] = $this->openSession();

        $this->postJson("{$base}/attendance-entries/{$entry->id}/events", [
            'kind' => 'went_for_lunch', 'at' => '2026-09-10 12:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    /* ------------------------------------------------- the unscoped variant */

    /*
     * A visit covering issues on several tickets is filed outside any of them
     * -- that is what the global create endpoint is for. It needs the same
     * freedom to keep adding to the session it just opened, because there is no
     * one ticket whose URL could honestly carry the events either.
     */

    public function test_an_event_can_be_added_without_naming_a_ticket(): void
    {
        [$entry] = $this->openSession();

        $data = $this->postJson("/api/attendance-entries/{$entry->id}/events", [
            'kind' => 'travel_start',
            'at' => '2026-09-10 08:30:00',
        ])->assertCreated()->json('data');

        $this->assertSame(
            ['clock_in', 'travel_start'],
            array_column($data['events'], 'kind')
        );
    }

    public function test_the_unscoped_variant_corrects_and_strikes_too(): void
    {
        [$entry] = $this->openSession();
        $event = $entry->events()->sole();

        $this->patchJson("/api/attendance-entries/{$entry->id}/events/{$event->id}", [
            'at' => '2026-09-10 07:45:00',
        ])->assertOk();

        $this->assertSame('2026-09-10 07:45:00', $entry->fresh()->start_clock->toDateTimeString());

        $this->postJson("/api/attendance-entries/{$entry->id}/events/{$event->id}/mistaken")
            ->assertOk();

        $this->assertNull($entry->fresh()->start_clock);
    }

    /** The ownership check is not the URL's scoping -- it is explicit, so it
     *  has to hold on the unscoped route as well. */
    public function test_the_unscoped_variant_still_refuses_another_sessions_event(): void
    {
        [$entry] = $this->openSession();
        $event = $entry->events()->sole();

        $other = AttendanceEntry::create(['technician_id' => $entry->technician_id]);

        $this->patchJson("/api/attendance-entries/{$other->id}/events/{$event->id}", [
            'at' => '2026-09-10 07:45:00',
        ])->assertNotFound();

        $this->assertSame('2026-09-10 08:00:00', $entry->fresh()->start_clock->toDateTimeString());
    }

    /* ------------------------------------------------------------- behaviour */

    /**
     * An open session is somebody currently working, not a mistake. Under the
     * old shape it emitted incomplete_pair:work, which is also why the previous
     * UI stopped writing one record per event -- doing so manufactured that
     * warning every single time.
     */
    public function test_an_open_session_warns_about_nothing(): void
    {
        [$entry, $base] = $this->openSession();

        $data = $this->postJson("{$base}/attendance-entries/{$entry->id}/events", [
            'kind' => 'travel_start', 'at' => '2026-09-10 08:30:00',
        ])->json('data');

        $this->assertSame([], $data['durations']['warnings']);
        $this->assertNull($data['end_clock']);
    }

    /** Reality does not arrive in field order. */
    public function test_events_recorded_out_of_order_still_add_up(): void
    {
        [$entry, $base] = $this->openSession();

        foreach ([
            ['clock_out', '2026-09-10 16:00:00'],
            ['break_end', '2026-09-10 12:30:00'],
            ['break_start', '2026-09-10 12:00:00'],
        ] as [$kind, $at]) {
            $this->postJson("{$base}/attendance-entries/{$entry->id}/events", compact('kind', 'at'))
                ->assertCreated();
        }

        $fresh = $entry->fresh()->load('events');

        $this->assertSame(450, $fresh->durations()['work']);
        $this->assertSame([], $fresh->durations()['warnings']);
        // The stream reads in time order regardless of the order it went in.
        $this->assertSame(
            ['clock_in', 'break_start', 'break_end', 'clock_out'],
            $fresh->liveEvents()->map(fn ($e) => $e->kind->value)->all()
        );
    }
}
