<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\Technician;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * No two technicians, technician categories, parts or suppliers share a name,
 * ignoring capitals and repeated spaces. The unique `name_key` is the guard;
 * the UniqueName rule turns a clash into a readable 422.
 */
class UniqueNameTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
    }

    public function test_names_are_stored_tidied_with_their_key(): void
    {
        $this->postJson('/api/technicians', ['name' => '  Ahmed   Ali '], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ahmed Ali');

        $technician = Technician::query()->sole();
        $this->assertSame('Ahmed Ali', $technician->name);
        $this->assertSame('ahmed ali', $technician->name_key);

        $supplier = Supplier::query()->create(['name' => ' Home   Depot']);
        $this->assertSame('Home Depot', $supplier->name);
        $this->assertSame('home depot', $supplier->name_key);
    }

    public function test_a_second_technician_with_the_same_name_is_refused_in_plain_words(): void
    {
        Technician::factory()->create(['name' => 'Ahmed Ali']);

        $this->postJson('/api/technicians', ['name' => 'AHMED  ali'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'There is already a technician called AHMED ali.');
    }

    public function test_a_deleted_technician_still_holds_the_name(): void
    {
        Technician::factory()->create(['name' => 'Ahmed Ali'])->delete();

        $this->postJson('/api/technicians', ['name' => 'ahmed ali'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_renaming_ignores_the_row_itself_but_not_the_others(): void
    {
        $ahmed = Technician::factory()->create(['name' => 'Ahmed Ali']);
        Technician::factory()->create(['name' => 'Bilal']);

        $this->patchJson("/api/technicians/{$ahmed->id}", ['name' => 'ahmed ALI'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'ahmed ALI');

        $this->patchJson("/api/technicians/{$ahmed->id}", ['name' => 'bilal'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'There is already a technician called bilal.');
    }

    public function test_categories_and_parts_follow_the_same_rule(): void
    {
        // In-house, Company and Independent are seeded by the migration.
        $this->postJson('/api/categories', ['name' => 'company'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'There is already a technician category called company.');

        Part::factory()->create(['name' => 'Igniter']);
        $this->postJson('/api/parts', ['name' => ' igniter '], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'There is already a part called igniter.');

        $category = Category::query()->where('name_key', 'company')->sole();
        $this->patchJson("/api/categories/{$category->id}", ['name' => 'Company'], $this->headers())->assertOk();
    }

    public function test_the_database_refuses_a_duplicate_the_rule_never_saw(): void
    {
        Supplier::query()->create(['name' => 'Walmart']);

        $this->expectException(UniqueConstraintViolationException::class);
        Supplier::query()->create(['name' => 'WALMART']);
    }
}
