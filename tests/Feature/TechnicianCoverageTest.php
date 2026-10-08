<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Coverage: the stores a technician can cover, where they are based, and notes
 * about it -- set with the technician, read on every technician read.
 */
class TechnicianCoverageTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        Store::factory()->create(['store_number' => '03795-00001']);
        Store::factory()->create(['store_number' => '03795-00002']);
        Store::factory()->create(['store_number' => '03795-00003']);
    }

    public function test_coverage_location_and_notes_are_saved_with_the_technician(): void
    {
        $created = $this->postJson('/api/technicians', [
            'name' => 'Ahmad',
            'location' => 'Columbus, OH',
            'coverage_notes' => 'North stores only on weekends',
            'coverage_stores' => ['03795-00002', '03795-00001'],
        ], $this->headers())->assertCreated()->json('data');

        $this->assertSame('Columbus, OH', $created['location']);
        $this->assertSame('North stores only on weekends', $created['coverage_notes']);
        $this->assertSame(['03795-00001', '03795-00002'], array_column($created['coverage_stores'], 'store_number'));

        $this->getJson("/api/technicians/{$created['id']}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.coverage_stores.1.store_number', '03795-00002');

        $this->getJson('/api/technicians?per_page=50', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.location', 'Columbus, OH')
            ->assertJsonCount(2, 'data.0.coverage_stores');
    }

    public function test_coverage_is_replaced_when_sent_and_kept_when_not(): void
    {
        $technician = Technician::factory()->create();
        $technician->coverageStores()->sync(Store::query()->pluck('id'));

        // Not sent: untouched.
        $this->patchJson("/api/technicians/{$technician->id}", ['phone' => '555-0100'], $this->headers())
            ->assertOk()->assertJsonCount(3, 'data.coverage_stores');

        $this->patchJson("/api/technicians/{$technician->id}", ['coverage_stores' => ['03795-00003']], $this->headers())
            ->assertOk()->assertJsonPath('data.coverage_stores.0.store_number', '03795-00003')->assertJsonCount(1, 'data.coverage_stores');

        $this->patchJson("/api/technicians/{$technician->id}", ['coverage_stores' => []], $this->headers())
            ->assertOk()->assertJsonCount(0, 'data.coverage_stores');
    }

    public function test_unknown_stores_are_refused(): void
    {
        $this->postJson('/api/technicians', ['name' => 'Sam', 'coverage_stores' => ['99999-99999']], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('coverage_stores.0');
    }
}
