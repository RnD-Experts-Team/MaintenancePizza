<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Suppliers (docs/MAINTENANCE.md §1), over HTTP.
 */
class SupplierTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
    }

    public function test_a_supplier_is_created_read_edited_deleted_and_restored(): void
    {
        $id = $this->postJson('/api/suppliers', [
            'name' => '  Home   Depot ',
            'phone' => '555-0100',
            'website' => 'homedepot.com',
            'description' => 'Hardware',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Home Depot')
            ->assertJsonPath('data.total_paid', '0.00')
            ->assertJsonPath('data.created_by', $this->authUser->id)
            ->json('data.id');

        $this->patchJson("/api/suppliers/{$id}", ['website' => 'https://www.homedepot.com'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.website', 'https://www.homedepot.com')
            ->assertJsonPath('data.name', 'Home Depot');

        $this->deleteJson("/api/suppliers/{$id}", [], $this->headers())->assertNoContent();
        $this->getJson('/api/suppliers', $this->headers())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/suppliers?with_trashed=1', $this->headers())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/suppliers/{$id}", $this->headers())->assertOk()->assertJsonPath('data.deleted_at', fn ($v) => $v !== null);

        $this->postJson("/api/suppliers/{$id}/restore", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.deleted_at', null);
    }

    public function test_names_are_unique_ignoring_case_and_spaces_including_deleted_ones(): void
    {
        Supplier::query()->create(['name' => 'Home Depot']);
        $deleted = Supplier::query()->create(['name' => 'Walmart']);
        $deleted->delete();

        $this->postJson('/api/suppliers', ['name' => 'HOME   depot'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'There is already a supplier called HOME depot.');

        $this->postJson('/api/suppliers', ['name' => ' walmart'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $amazon = Supplier::query()->create(['name' => 'Amazon']);
        $this->patchJson("/api/suppliers/{$amazon->id}", ['name' => 'AMAZON'], $this->headers())->assertOk();
        $this->patchJson("/api/suppliers/{$amazon->id}", ['name' => 'home depot'], $this->headers())->assertUnprocessable();
    }

    public function test_search_notes_attachments_and_total_paid(): void
    {
        $depot = Supplier::query()->create(['name' => 'Home Depot', 'phone' => '555-1234']);
        Supplier::query()->create(['name' => 'Amazon']);

        $this->getJson('/api/suppliers?search=depot', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Home Depot')
            ->assertJsonPath('meta.total', 1);

        $this->postJson("/api/suppliers/{$depot->id}/notes", ['body' => 'Ask for Joe'], $this->headers())->assertCreated();
        $this->post("/api/suppliers/{$depot->id}/attachments", [
            'files' => [UploadedFile::fake()->create('terms.pdf', 10)],
        ], $this->headers())->assertCreated();

        Payment::query()->create(['paid_at' => '2026-10-01', 'supplier_id' => $depot->id, 'total' => '100.50']);
        Payment::query()->create(['paid_at' => '2026-10-02', 'supplier_id' => $depot->id, 'total' => '20.00', 'mistaken' => true]);

        $this->getJson("/api/suppliers/{$depot->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_paid', '100.50')
            ->assertJsonPath('data.notes.0.body', 'Ask for Joe')
            ->assertJsonCount(1, 'data.attachments');
    }

    public function test_list_rows_carry_total_paid_without_a_query_per_row(): void
    {
        $depot = Supplier::query()->create(['name' => 'Home Depot']);
        Supplier::query()->create(['name' => 'Amazon']);
        Payment::query()->create(['paid_at' => '2026-10-01', 'supplier_id' => $depot->id, 'total' => '100.50']);
        Payment::query()->create(['paid_at' => '2026-10-03', 'supplier_id' => $depot->id, 'total' => '9.50']);
        Payment::query()->create(['paid_at' => '2026-10-02', 'supplier_id' => $depot->id, 'total' => '20.00', 'mistaken' => true]);

        $list = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $this->getJson('/api/suppliers', $this->headers())->assertOk()->json('data');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$rows, $n];
        };

        [$rows, $few] = $list();
        $this->assertSame(['Amazon' => '0.00', 'Home Depot' => '110.00'], array_column($rows, 'total_paid', 'name'));

        foreach (range(1, 5) as $i) {
            $s = Supplier::query()->create(['name' => "Supplier {$i}"]);
            Payment::query()->create(['paid_at' => '2026-10-01', 'supplier_id' => $s->id, 'total' => $i]);
        }
        [$rows, $more] = $list();
        $this->assertSame('3.00', collect($rows)->firstWhere('name', 'Supplier 3')['total_paid']);
        $this->assertSame($few, $more, 'The supplier list ran more queries with more suppliers.');
    }
}
