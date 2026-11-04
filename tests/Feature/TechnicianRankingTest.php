<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianIssueAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * GET technicians filters and the picker ranking (docs/MAINTENANCE.md §1):
 * covers store → covers issue → emergency (urgent=1) → call first on that
 * issue → rating desc → name.
 */
class TechnicianRankingTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Issue $issue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        Store::factory()->create(['store_number' => '03795-00002']);
        $this->issue = Issue::factory()->create(['title' => 'Oven']);
    }

    private function ability(Technician $t, ?int $rating, bool $callFirst = false, ?Issue $issue = null): void
    {
        $a = new TechnicianIssueAbility(['technician_id' => $t->id, 'issue_id' => ($issue ?? $this->issue)->id, 'rating' => $rating]);
        $a->call_first = $callFirst ? true : null;
        $a->save();
    }

    /** @return array<int, string> */
    private function names(string $query): array
    {
        return array_column($this->getJson("/api/technicians?{$query}", $this->headers())->assertOk()->json('data'), 'name');
    }

    public function test_ranking_by_store_issue_call_first_rating_and_name(): void
    {
        $both = Technician::factory()->create(['name' => 'Zed both']);
        $both->coverageStores()->sync([$this->store->id]);
        $this->ability($both, 2);

        $storeOnly = Technician::factory()->create(['name' => 'Yan store only']);
        $storeOnly->coverageStores()->sync([$this->store->id]);

        $pinned = Technician::factory()->create(['name' => 'Xavi pinned']);
        $this->ability($pinned, 3, callFirst: true);

        $five = Technician::factory()->create(['name' => 'Will five']);
        $this->ability($five, 5);

        $unratedA = Technician::factory()->create(['name' => 'Abe unrated']);
        $this->ability($unratedA, null);

        $nobody = Technician::factory()->create(['name' => 'Covers nothing']);

        $this->assertSame(
            ['Zed both', 'Yan store only', 'Xavi pinned', 'Will five', 'Abe unrated'],
            $this->names("store=03795-00001&issue_id={$this->issue->id}")
        );

        $rows = $this->getJson("/api/technicians?store=03795-00001&issue_id={$this->issue->id}", $this->headers())->json('data');
        $this->assertSame(['covers_store' => true, 'covers_issue' => true, 'call_first' => false, 'rating' => 2], $rows[0]['standing']);
        $this->assertSame(['covers_store' => false, 'covers_issue' => true, 'call_first' => true, 'rating' => 3], $rows[2]['standing']);

        // Only the issue: everyone listed covers it; the store question is not asked.
        $this->assertSame(['Xavi pinned', 'Will five', 'Zed both', 'Abe unrated'], $this->names("issue_id={$this->issue->id}"));
        $rows = $this->getJson("/api/technicians?issue_id={$this->issue->id}", $this->headers())->json('data');
        $this->assertNull($rows[0]['standing']['covers_store']);

        // Only the store.
        $this->assertSame(['Yan store only', 'Zed both'], $this->names('store=03795-00001'));

        // No ranking: no standing.
        $this->assertArrayNotHasKey('standing', $this->getJson('/api/technicians', $this->headers())->json('data.0'));
        $this->assertContains($nobody->name, $this->names(''));
    }

    public function test_urgent_puts_emergency_technicians_before_the_call_first_pin(): void
    {
        $pinned = Technician::factory()->create(['name' => 'Pinned']);
        $this->ability($pinned, 5, callFirst: true);

        $night = Technician::factory()->create(['name' => 'Night owl', 'emergency_available' => true]);
        $this->ability($night, 1);

        $this->assertSame(['Pinned', 'Night owl'], $this->names("issue_id={$this->issue->id}"));
        $this->assertSame(['Night owl', 'Pinned'], $this->names("issue_id={$this->issue->id}&urgent=1"));
        $this->assertSame(['Night owl'], $this->names('emergency=1'));
    }

    public function test_search_category_and_trashed_filters(): void
    {
        $company = Category::query()->where('name', 'Company')->firstOrFail();
        Technician::factory()->create(['name' => 'Acme Ovens', 'category_id' => $company->id, 'contact_person' => 'Joe']);
        Technician::factory()->create(['name' => 'Bob', 'phone' => '555-0199']);
        Technician::factory()->create(['name' => 'Gone'])->delete();

        $this->assertSame(['Acme Ovens'], $this->names('search=acme'));
        $this->assertSame(['Acme Ovens'], $this->names('search=joe'));
        $this->assertSame(['Bob'], $this->names('search=0199'));
        $this->assertSame(['Acme Ovens'], $this->names("category_id={$company->id}"));
        $this->assertNotContains('Gone', $this->names(''));
        $this->assertContains('Gone', $this->names('with_trashed=1'));
    }

    public function test_show_lists_the_covered_issues(): void
    {
        $t = Technician::factory()->create(['name' => 'Ahmad']);
        $this->ability($t, 4, callFirst: true);
        $this->ability($t, null, issue: Issue::factory()->create(['title' => 'Ice machine']));

        $this->getJson("/api/technicians/{$t->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.covered_issue_count', 2)
            ->assertJsonPath('data.average_rating', '4.0')
            ->assertJsonPath('data.covered_issues.0.title', 'Ice machine')
            ->assertJsonPath('data.covered_issues.0.rating', null)
            ->assertJsonPath('data.covered_issues.1.issue_id', $this->issue->id)
            ->assertJsonPath('data.covered_issues.1.call_first', true);

        $this->assertArrayNotHasKey('covered_issues', $this->getJson('/api/technicians', $this->headers())->json('data.0'));
    }

    public function test_rows_carry_what_they_were_paid_this_calendar_month(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        try {
            $tom = Technician::factory()->create(['name' => 'Tom']);
            $ann = Technician::factory()->create(['name' => 'Ann']);
            $tom->coverageStores()->attach($this->store->id);
            $pay = fn (Technician $t, string $at, string $total, bool $mistaken = false) => Payment::query()->create([
                'paid_at' => $at, 'technician_id' => $t->id, 'total' => $total, 'mistaken' => $mistaken,
            ]);
            $pay($tom, '2026-10-01', '100.00');
            $pay($tom, '2026-10-31', '25.50');
            $pay($tom, '2026-09-30', '999.00');
            $pay($tom, '2026-11-01', '999.00');
            $pay($tom, '2026-10-10', '999.00', mistaken: true);
            $pay($ann, '2026-09-15', '50.00');

            $plain = array_column($this->getJson('/api/technicians', $this->headers())->assertOk()->json('data'), 'paid_this_month', 'name');
            $this->assertSame('125.50', $plain['Tom']);
            $this->assertSame('0.00', $plain['Ann']);

            // The ranked list (store / issue given) carries it too.
            $ranked = $this->getJson('/api/technicians?store=03795-00001', $this->headers())->assertOk()->json('data');
            $this->assertSame('Tom', $ranked[0]['name']);
            $this->assertSame('125.50', $ranked[0]['paid_this_month']);
            $this->assertTrue($ranked[0]['standing']['covers_store']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
