<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\TroubleshootingFix;
use App\Models\TroubleshootingGuide;
use App\Services\TicketStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The analytics page: one or many stores, a range of instants (the viewer's
 * local "yesterday" by default), and four questions -- what was filed, what
 * changed, what keeps coming back, what nobody has touched.
 */
class MaintenanceAnalyticsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Issue $oven;

    /** New York's Oct 5, as the browser sends it: its local midnights in UTC. */
    private const YESTERDAY = ['from' => '2026-10-05T04:00:00Z', 'to' => '2026-10-06T04:00:00Z'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->oven = Issue::factory()->create(['title' => 'Oven']);
    }

    private function url(string $section, array $query = []): string
    {
        return "/api/maintenance-analytics/{$section}?" . http_build_query(array_merge(
            ['stores' => [$this->store->store_number]] + self::YESTERDAY,
            $query,
        ));
    }

    /**
     * An Oven ticket opened at $at -- created then, so its updated_at is $at
     * too (its issue touches it). The clock is put back afterwards.
     */
    private function ovenTicket(string $at, ?Store $store = null, string $status = 'pending'): Ticket
    {
        $now = now();
        $this->travelTo($at);

        $ticket = Ticket::factory()->for($store ?? $this->store)->create();
        TicketIssue::factory()->for($ticket)->create([
            'issue_id' => $this->oven->id, 'other_title' => null, 'status' => $status,
        ]);

        $this->travelTo($now);

        return $ticket->fresh();
    }

    /** Something happens on the ticket at $at: its issue moves to $status. */
    private function changeAt(Ticket $ticket, string $at, IssueStatus $status): void
    {
        $now = now();
        $this->travelTo($at);
        TicketStatusService::changeIssueStatus($ticket->ticketIssues()->first(), $status);
        $this->travelTo($now);
    }

    public function test_stores_are_required_and_ranges_are_bounded(): void
    {
        $this->getJson('/api/maintenance-analytics/summary?' . http_build_query(self::YESTERDAY), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('stores');

        $this->getJson($this->url('summary', ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-10-01T00:00:00Z']), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->getJson('/api/maintenance-analytics/everything?' . http_build_query(['stores' => ['03795-00001']] + self::YESTERDAY), $this->headers())
            ->assertNotFound();
    }

    public function test_yesterday_is_the_viewers_yesterday_not_utcs(): void
    {
        $lateEvening = $this->ovenTicket('2026-10-06 03:30:00');  // 11:30 PM Oct 5 in New York
        $this->ovenTicket('2026-10-06 04:30:00');                  // 12:30 AM Oct 6 in New York
        $this->ovenTicket('2026-10-05 03:59:00');                  // 11:59 PM Oct 4 in New York

        $summary = $this->getJson($this->url('summary'), $this->headers())->assertOk()->json('data');

        $this->assertSame(1, $summary['kpis']['tickets_created']);
        $this->assertSame([$lateEvening->id], array_column($summary['created'], 'ticket_id'));
    }

    public function test_completions_and_how_long_they_took(): void
    {
        $this->travelTo('2026-10-04 10:00:00');
        $ticket = $this->ovenTicket('2026-10-04 10:00:00', status: 'in_progress');
        $issue = $ticket->ticketIssues()->first();

        $this->travelTo('2026-10-05 16:00:00'); // 30 hours later, inside the range
        $this->postJson("/api/stores/{$this->store->store_number}/tickets/{$ticket->id}/issues/status",
            ['ticket_issue_ids' => [$issue->id], 'status' => 'complete'], $this->headers())->assertOk();

        $summary = $this->getJson($this->url('summary'), $this->headers())->assertOk()->json('data');

        $this->assertSame(1, $summary['kpis']['issues_completed']);
        $this->assertEquals(30.0, $summary['kpis']['avg_hours_to_complete']);
        $this->assertSame('Oven', $summary['completion_by_issue'][0]['title']);
    }

    public function test_recurring_counts_separate_tickets_in_the_window(): void
    {
        foreach (['2026-08-01', '2026-09-01', '2026-10-05'] as $day) {
            $this->ovenTicket("{$day} 12:00:00");
        }
        // Not counted: a deferral follow-up (same report), an archived ticket,
        // and a ticket older than 90 days.
        $deferred = $this->ovenTicket('2026-09-10 12:00:00');
        TicketIssue::factory()->for($deferred)->create([
            'issue_id' => $this->oven->id, 'other_title' => null, 'parent_id' => $deferred->ticketIssues()->first()->id,
            'created_at' => '2026-09-11 12:00:00',
        ]);
        $this->ovenTicket('2026-09-20 12:00:00')->delete();
        $this->ovenTicket('2026-06-01 12:00:00');

        $recurring = $this->getJson($this->url('watchlist'), $this->headers())->assertOk()->json('data.recurring');

        $this->assertCount(1, $recurring);
        $this->assertSame('Oven', $recurring[0]['title']);
        $this->assertSame(4, $recurring[0]['count'], 'Aug 1, Sep 1, Sep 10 and Oct 5');
        $this->assertSame('03795-00001', $recurring[0]['store_number']);

        // And the ticket filed yesterday says so.
        $created = $this->getJson($this->url('summary'), $this->headers())->json('data.created');
        $this->assertSame(4, $created[0]['issues'][0]['recurring_count']);
    }

    public function test_untouched_means_open_and_unchanged_for_a_day_or_more(): void
    {
        $silent = $this->ovenTicket('2026-10-01 09:00:00');
        $this->changeAt($silent, '2026-10-04 09:00:00', IssueStatus::Assigned);

        $neverTouched = $this->ovenTicket('2026-10-03 09:00:00');

        $busy = $this->ovenTicket('2026-10-01 09:00:00');
        $this->changeAt($busy, '2026-10-06 11:00:00', IssueStatus::Assigned);

        $this->ovenTicket('2026-09-01 09:00:00', status: IssueStatus::Complete->value);   // closed: not "untouched"
        $this->ovenTicket('2026-09-01 09:00:00', status: IssueStatus::Deferred->value);

        $this->travelTo('2026-10-06 12:00:00');
        $untouched = $this->getJson($this->url('watchlist'), $this->headers())->assertOk()->json('data.untouched');

        $this->assertSame([$neverTouched->id, $silent->id], array_column($untouched, 'ticket_id'), 'longest silence first');
        $this->assertSame(3, $untouched[0]['days_silent']);
        $this->assertSame(2, $untouched[1]['days_silent']);
        $this->assertSame('Oven', $untouched[1]['open_issues'][0]['title']);
    }

    public function test_what_changed_lists_the_tickets_changed_in_the_range_and_how(): void
    {
        $other = Store::factory()->create();
        $mine = $this->ovenTicket('2026-10-01 09:00:00');
        $theirs = $this->ovenTicket('2026-10-01 09:00:00', store: $other);
        $quiet = $this->ovenTicket('2026-10-01 09:00:00');

        $this->changeAt($mine, '2026-10-05 13:00:00', IssueStatus::Assigned);
        $this->travelTo('2026-10-05 14:00:00');
        $mine->ticketIssues()->first()->notes()->create(['body' => 'Tech booked for Monday']);
        $secret = $mine->notes()->make(['body' => 'Vendor quoted $900']);
        $secret->is_private = true;
        $secret->save();

        $this->changeAt($theirs, '2026-10-05 13:00:00', IssueStatus::Assigned);   // another store
        $this->changeAt($quiet, '2026-10-05 03:00:00', IssueStatus::Assigned);    // before the range

        $this->travelTo('2026-10-06 12:00:00');
        $page = $this->getJson($this->url('activity'), $this->headers())->assertOk();

        $this->assertSame(1, $page->json('total'));
        $this->assertSame($mine->id, $page->json('data.0.ticket_id'));
        $changes = $page->json('data.0.changes');
        $this->assertFalse($changes['opened']);
        $this->assertSame('Oven', $changes['status_changes'][0]['title']);
        $this->assertSame(['Pending', 'Assigned'], [$changes['status_changes'][0]['from'], $changes['status_changes'][0]['to']]);
        $this->assertSame(1, $changes['notes'], 'the private note is not counted');

        $this->assertSame(1, $this->getJson($this->url('summary'), $this->headers())->json('data.kpis.changed_tickets'));
    }

    public function test_problems_fixed_by_troubleshooting_are_counted_and_listed(): void
    {
        $guide = TroubleshootingGuide::query()->create(['issue_id' => $this->oven->id, 'title' => "Won't heat"]);
        $other = Store::factory()->create(['store_number' => '03795-00002']);

        $fix = function (string $at, Store $store, ?TroubleshootingGuide $guide) {
            $now = now();
            $this->travelTo($at);
            TroubleshootingFix::query()->create([
                'store_id' => $store->id,
                'issue_id' => $this->oven->id,
                'troubleshooting_guide_id' => $guide?->id,
                'snapshot' => ['outcome' => 'fixed', 'guide_title' => $guide?->title],
            ]);
            $this->travelTo($now);
        };

        $fix('2026-10-05 15:00:00', $this->store, $guide);
        $fix('2026-10-06 03:30:00', $this->store, null);     // 11:30 PM Oct 5 in New York
        $fix('2026-10-06 05:00:00', $this->store, $guide);   // Oct 6: outside
        $fix('2026-10-05 15:00:00', $other, $guide);         // another store

        $this->getJson($this->url('summary'), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.kpis.fixed_by_troubleshooting', 2)
            ->assertJsonCount(2, 'data.troubleshooting_fixes')
            ->assertJsonPath('data.troubleshooting_fixes.0.guide_title', null)
            ->assertJsonPath('data.troubleshooting_fixes.1.guide_title', "Won't heat")
            ->assertJsonPath('data.troubleshooting_fixes.1.issue_title', 'Oven')
            ->assertJsonPath('data.troubleshooting_fixes.1.store_number', '03795-00001');
    }

    public function test_several_stores_at_once(): void
    {
        $second = Store::factory()->create(['store_number' => '03795-00002']);
        $this->ovenTicket('2026-10-05 12:00:00');
        $this->ovenTicket('2026-10-05 13:00:00', store: $second);

        $summary = $this->getJson($this->url('summary', ['stores' => ['03795-00001', '03795-00002']]), $this->headers())
            ->assertOk()->json('data');

        $this->assertSame(2, $summary['kpis']['tickets_created']);
        $this->assertSame(['03795-00001', '03795-00002'], array_column($summary['by_store'], 'store_number'));
        $this->assertSame([1, 1], array_column($summary['by_store'], 'created'));
    }
}
