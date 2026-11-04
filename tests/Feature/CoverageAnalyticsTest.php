<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * GET maintenance-analytics/coverage (docs §6): store × issue, who we could
 * call, and where there is one technician or none.
 */
class CoverageAnalyticsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private Issue $oven;

    private Issue $fryer;

    private Category $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->a = Store::factory()->create(['store_number' => '03795-00001']);
        $this->b = Store::factory()->create(['store_number' => '03795-00002']);
        Store::factory()->create(['store_number' => '03795-00009']); // never had a ticket
        Ticket::factory()->for($this->a)->create();
        Ticket::factory()->for($this->b)->create();

        $this->oven = Issue::factory()->create(['title' => 'Oven']);
        $this->fryer = Issue::factory()->create(['title' => 'Fryer']);
        Issue::factory()->create(['title' => 'Old sign'])->delete();
        $this->company = Category::query()->where('name', 'Company')->firstOrFail();

        // Ann: A + B, oven (5) and fryer, on call at night.
        $this->technician('Ann', [$this->a, $this->b], [$this->oven->id => 5, $this->fryer->id => null], emergency: true);
        // Bob (Company): A, oven (3).
        $this->technician('Bob', [$this->a], [$this->oven->id => 3], category: $this->company);
        // Cy covers A only, no issues; Dee covers the oven only, no stores.
        $this->technician('Cy', [$this->a], []);
        $this->technician('Dee', [], [$this->oven->id => 4]);
        // Deleted: covers nothing.
        $this->technician('Eve', [$this->a, $this->b], [$this->oven->id => 1, $this->fryer->id => 1])->delete();
    }

    /** @param  array<int, int|null>  $issues  issue id => rating */
    private function technician(string $name, array $stores, array $issues, bool $emergency = false, ?Category $category = null): Technician
    {
        $t = Technician::factory()->create(['name' => $name, 'emergency_available' => $emergency, 'category_id' => $category?->id]);
        foreach ($stores as $store) {
            DB::table('store_technician')->insert(['technician_id' => $t->id, 'store_id' => $store->id]);
        }
        foreach ($issues as $issueId => $rating) {
            DB::table('technician_issue_abilities')->insert(['technician_id' => $t->id, 'issue_id' => $issueId, 'rating' => $rating]);
        }

        return $t;
    }

    private function cell(array $data, string $store, Issue $issue): array
    {
        return collect($data['cells'])->first(fn ($c) => $c['store_number'] === $store && $c['issue_id'] === $issue->id);
    }

    public function test_defaults_to_stores_with_tickets_and_every_catalog_issue(): void
    {
        $data = $this->getJson('/api/maintenance-analytics/coverage', $this->headers())->assertOk()->json('data');

        // 2 stores × 2 live issues.
        $this->assertCount(4, $data['cells']);
        $this->assertSame(
            ['store_number' => '03795-00001', 'issue_id' => $this->oven->id, 'title' => 'Oven', 'technicians' => 2, 'emergency' => 1, 'average_rating' => '4.0', 'gap' => false],
            $this->cell($data, '03795-00001', $this->oven),
        );
        $this->assertSame(
            ['store_number' => '03795-00002', 'issue_id' => $this->fryer->id, 'title' => 'Fryer', 'technicians' => 1, 'emergency' => 1, 'average_rating' => null, 'gap' => true],
            $this->cell($data, '03795-00002', $this->fryer),
        );
        $this->assertSame(1, $this->cell($data, '03795-00002', $this->oven)['technicians']);
        $this->assertSame(3, $data['gaps']);

        $this->assertSame([
            'technicians_for_stores' => 3,  // Ann, Bob, Cy
            'technicians_for_issues' => 3,  // Ann, Bob, Dee
            'technicians_for_both' => 2,    // Ann, Bob
            'emergency_available' => 1,     // Ann
        ], $data['kpis']);
    }

    public function test_requested_stores_issues_and_category(): void
    {
        $query = http_build_query(['stores' => ['03795-00009', '03795-00001'], 'issue_ids' => [$this->oven->id]]);
        $data = $this->getJson("/api/maintenance-analytics/coverage?{$query}", $this->headers())->assertOk()->json('data');

        // A store nobody covers still has its cell, as a gap.
        $this->assertSame(['03795-00001', '03795-00009'], array_column($data['cells'], 'store_number'));
        $this->assertSame([2, 0], array_column($data['cells'], 'technicians'));
        $this->assertSame(1, $data['gaps']);

        $query = http_build_query(['category_id' => $this->company->id]);
        $data = $this->getJson("/api/maintenance-analytics/coverage?{$query}", $this->headers())->assertOk()->json('data');
        $this->assertSame(1, $this->cell($data, '03795-00001', $this->oven)['technicians']);
        $this->assertSame('3.0', $this->cell($data, '03795-00001', $this->oven)['average_rating']);
        $this->assertSame(4, $data['gaps']);
        $this->assertSame(1, $data['kpis']['technicians_for_both']);
    }

    public function test_validation(): void
    {
        $this->getJson('/api/maintenance-analytics/coverage?' . http_build_query(['issue_ids' => [99999], 'stores' => ['nope']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issue_ids.0', 'stores.0']);

        $this->getJson('/api/maintenance-analytics/nonsense', $this->headers())->assertNotFound();
    }
}
