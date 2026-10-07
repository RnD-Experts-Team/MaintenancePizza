<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Part;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Catalog items can be edited in place. Before this, nothing in the catalog
 * had an update route: a technician who changed trade had to be deleted and
 * recreated, and a phone number typed on create was silently thrown away.
 */
class CatalogUpdateTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
    }

    public function test_a_technician_is_created_with_a_phone_number(): void
    {
        $category = Category::factory()->create(['name' => 'Refrigeration']);

        $this->postJson('/api/technicians', [
            'name' => 'Ahmed Saleh',
            'phone' => '+1 (234) 567-8900',
            'category_id' => $category->id,
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.phone', '+1 (234) 567-8900')
            ->assertJsonPath('data.category.name', 'Refrigeration');
    }

    public function test_a_technician_can_change_category_phone_and_name_after_creation(): void
    {
        $technician = Technician::factory()->create(['name' => 'Ahmed', 'category_id' => null]);
        $hvac = Category::factory()->create(['name' => 'HVAC']);

        $this->patchJson("/api/technicians/{$technician->id}", [
            'category_id' => $hvac->id,
            'phone' => '234.567.8900 x12',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Ahmed')
            ->assertJsonPath('data.category_id', $hvac->id)
            ->assertJsonPath('data.category.name', 'HVAC')
            ->assertJsonPath('data.phone', '234.567.8900 x12');

        $this->patchJson("/api/technicians/{$technician->id}", ['category_id' => null, 'name' => 'Ahmed Saleh'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.name', 'Ahmed Saleh')
            ->assertJsonPath('data.phone', '234.567.8900 x12');
    }

    public function test_a_phone_that_is_not_a_phone_is_refused(): void
    {
        $technician = Technician::factory()->create();

        foreach (['call me', '12', str_repeat('1', 40)] as $bad) {
            $this->patchJson("/api/technicians/{$technician->id}", ['phone' => $bad], $this->headers())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('phone');
        }
    }

    public function test_an_issue_title_and_description_can_be_edited(): void
    {
        $issue = Issue::factory()->create(['title' => 'Oven', 'description' => 'old']);

        $this->patchJson("/api/issues/{$issue->id}", ['description' => null], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.title', 'Oven')
            ->assertJsonPath('data.description', null);

        $this->patchJson("/api/issues/{$issue->id}", ['title' => ''], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_category_and_part_descriptions_are_kept(): void
    {
        $this->postJson('/api/categories', ['name' => 'Electrical', 'description' => 'Panels, breakers, lighting'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.description', 'Panels, breakers, lighting');

        $part = Part::factory()->create();
        $this->patchJson("/api/parts/{$part->id}", ['description' => 'Fits the ZX-200 only'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.description', 'Fits the ZX-200 only');

        $category = Category::factory()->create();
        Technician::factory()->count(2)->create(['category_id' => $category->id]);
        $this->patchJson("/api/categories/{$category->id}", ['name' => 'Cooking Equipment'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Cooking Equipment')
            ->assertJsonPath('data.technicians_count', 2);
    }

    public function test_an_edit_returns_the_items_notes(): void
    {
        $technician = Technician::factory()->create();
        $this->postJson("/api/technicians/{$technician->id}/notes", ['body' => 'Weekends only'], $this->headers())->assertCreated();

        $this->patchJson("/api/technicians/{$technician->id}", ['name' => 'Renamed'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.notes.0.body', 'Weekends only');
    }

    public function test_a_deleted_technician_cannot_be_edited(): void
    {
        $technician = Technician::factory()->create();
        $technician->delete();

        $this->patchJson("/api/technicians/{$technician->id}", ['name' => 'Ghost'], $this->headers())->assertNotFound();
    }
}
