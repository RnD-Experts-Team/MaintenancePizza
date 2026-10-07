<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Part;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * "Not sure that all notes and attachments are getting saved correctly."
 *
 * They were saved -- and then never read back: every catalog read loaded only
 * `creator`, so the presenters' `notes` / `attachments` were always null. A
 * note added to a technician vanished on the next refresh.
 */
class CatalogNotesTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->fakeAuthServer();
    }

    public function test_issue_notes_and_files_come_back_on_the_list(): void
    {
        $issue = Issue::factory()->create(['title' => 'Oven']);

        $this->post("/api/issues/{$issue->id}/notes", [
            'body' => 'Model ZX-200; the igniter is the usual culprit',
            'files' => [UploadedFile::fake()->create('manual.pdf', 20, 'application/pdf')],
        ], $this->headers())->assertCreated();
        $this->post("/api/issues/{$issue->id}/attachments", [
            'files' => [UploadedFile::fake()->image('nameplate.jpg')],
        ], $this->headers())->assertCreated();

        $row = collect($this->getJson('/api/issues', $this->headers())->assertOk()->json('data'))
            ->firstWhere('id', $issue->id);

        $this->assertSame('Model ZX-200; the igniter is the usual culprit', $row['notes'][0]['body']);
        $this->assertSame('manual.pdf', $row['notes'][0]['attachments'][0]['original_name']);
        $this->assertSame($this->authUser->id, $row['notes'][0]['creator']['id']);
        $this->assertSame('nameplate.jpg', $row['attachments'][0]['original_name']);
        $this->assertSame($this->authUser->id, $row['attachments'][0]['creator']['id']);
    }

    public function test_technician_category_and_part_lists_return_their_notes(): void
    {
        $technician = Technician::factory()->create();
        $category = Category::factory()->create();
        $part = Part::factory()->create();

        foreach (["technicians/{$technician->id}", "categories/{$category->id}", "parts/{$part->id}"] as $path) {
            $this->postJson("/api/{$path}/notes", ['body' => "note on {$path}"], $this->headers())->assertCreated();
        }

        foreach (['technicians' => $technician->id, 'categories' => $category->id, 'parts' => $part->id] as $list => $id) {
            $row = collect($this->getJson("/api/{$list}", $this->headers())->assertOk()->json('data'))->firstWhere('id', $id);

            $this->assertCount(1, $row['notes'], "{$list} lost its note");
            $this->assertSame([], $row['attachments']);
        }
    }

    public function test_restore_returns_the_item_with_its_notes(): void
    {
        $technician = Technician::factory()->create();
        $this->postJson("/api/technicians/{$technician->id}/notes", ['body' => 'Calls back fast'], $this->headers())->assertCreated();
        $technician->delete();

        $this->postJson("/api/technicians/{$technician->id}/restore", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.notes.0.body', 'Calls back fast');
    }
}
