<?php

namespace Tests\Feature;

use App\Models\MaintenanceSetting;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * GET / PUT maintenance-settings (docs/MAINTENANCE.md §2 "Settings").
 */
class MaintenanceSettingTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
    }

    public function test_an_empty_table_reads_as_the_defaults(): void
    {
        $this->getJson('/api/maintenance-settings', $this->headers())->assertOk()
            ->assertExactJson(['data' => [
                'approval_levels' => [
                    ['max' => '300.00', 'level' => 'specialist', 'label' => 'Maintenance Specialist'],
                    ['max' => '1000.00', 'level' => 'manager', 'label' => 'Maintenance Manager'],
                    ['max' => null, 'level' => 'management', 'label' => 'Management'],
                ],
                'trip_link_idle_hours' => 48,
            ]]);
    }

    public function test_settings_are_saved_and_used(): void
    {
        $this->putJson('/api/maintenance-settings', [
            'approval_levels' => [
                ['max' => 500, 'level' => 'specialist', 'label' => 'Specialist'],
                ['max' => null, 'level' => 'owner', 'label' => 'Owner'],
            ],
            'trip_link_idle_hours' => 12,
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.approval_levels.0.max', '500.00')
            ->assertJsonPath('data.approval_levels.1.level', 'owner')
            ->assertJsonPath('data.trip_link_idle_hours', 12);

        $this->assertSame($this->authUser->id, MaintenanceSetting::query()->where('key', 'approval_levels')->value('updated_by'));

        // Only the key sent changes.
        $this->putJson('/api/maintenance-settings', ['trip_link_idle_hours' => 24], $this->headers())->assertOk()
            ->assertJsonPath('data.approval_levels.1.level', 'owner')
            ->assertJsonPath('data.trip_link_idle_hours', 24);

        // A new trip's link lives that long.
        $result = app(TripService::class)->scheduleIssue(TicketIssue::factory()->create(), Technician::factory()->create(), '2026-11-03', null, 'work', null, User::query()->findOrFail($this->authUser->id));
        $this->assertSame(24, $result['trip']->link_idle_hours);
    }

    public function test_trip_link_idle_hours_must_be_between_1_and_720(): void
    {
        foreach ([0, 721, 'soon'] as $bad) {
            $this->putJson('/api/maintenance-settings', ['trip_link_idle_hours' => $bad], $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors(['trip_link_idle_hours']);
        }

        $this->putJson('/api/maintenance-settings', ['trip_link_idle_hours' => 720], $this->headers())->assertOk();
    }

    public function test_approval_levels_must_be_a_usable_ladder(): void
    {
        $bad = [
            'empty' => [[], 'approval_levels'],
            'last has a max' => [[['max' => 300, 'level' => 'a', 'label' => 'A']], 'approval_levels.0.max'],
            'middle has no max' => [[['max' => null, 'level' => 'a', 'label' => 'A'], ['max' => null, 'level' => 'b', 'label' => 'B']], 'approval_levels.0.max'],
            'not rising' => [[['max' => 500, 'level' => 'a', 'label' => 'A'], ['max' => 300, 'level' => 'b', 'label' => 'B'], ['max' => null, 'level' => 'c', 'label' => 'C']], 'approval_levels.1.max'],
            'repeated level' => [[['max' => 300, 'level' => 'a', 'label' => 'A'], ['max' => null, 'level' => 'a', 'label' => 'B']], 'approval_levels.1.level'],
            'no label' => [[['max' => null, 'level' => 'a']], 'approval_levels.0.label'],
            'no max key' => [[['level' => 'a', 'label' => 'A']], 'approval_levels.0.max'],
        ];

        foreach ($bad as $case => [$levels, $field]) {
            $this->putJson('/api/maintenance-settings', ['approval_levels' => $levels], $this->headers())
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field], 'errors', "case: {$case}");
        }

        $this->assertSame(0, MaintenanceSetting::query()->count());
    }

    public function test_a_broken_stored_value_falls_back_to_the_defaults(): void
    {
        MaintenanceSetting::put('approval_levels', [['max' => 5, 'level' => '', 'label' => '']]);

        $this->getJson('/api/maintenance-settings', $this->headers())->assertOk()
            ->assertJsonPath('data.approval_levels.2.level', 'management');

        $this->assertSame(0, Trip::query()->count());
    }
}
