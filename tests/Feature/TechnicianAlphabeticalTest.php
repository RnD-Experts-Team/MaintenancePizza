<?php

namespace Tests\Feature;

use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The technicians list is A to Z (whatever the case) unless a store or an
 * issue is picked, which ranks it instead.
 */
class TechnicianAlphabeticalTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    public function test_the_default_list_is_a_to_z_not_newest_first(): void
    {
        $this->fakeAuthServer();
        foreach (['zeta heating', 'Alpha Electric', 'bravo Plumbing', 'Charlie Doors'] as $name) {
            Technician::factory()->create(['name' => $name]);
        }

        $names = $this->getJson('/api/technicians?per_page=50', $this->headers())->assertOk()->json('data.*.name');

        $this->assertSame(['Alpha Electric', 'bravo Plumbing', 'Charlie Doors', 'zeta heating'], $names);
    }
}
