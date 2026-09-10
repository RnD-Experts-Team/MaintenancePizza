<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\DailyPayEntry;
use App\Models\Note;
use App\Models\Store;
use App\Models\Technician;
use App\Services\DailyPayEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Editing a pay entry replaces it wholesale, which is where the two ways of
 * losing work live: overwriting someone else's save, and destroying the notes
 * and files the revision snapshot still points at.
 */
class DailyPayEditSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->technician = Technician::factory()->create();
        $this->store = Store::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'date' => '2026-09-10',
            'payments' => [[
                'technician_id' => $this->technician->id,
                'hourly_payment_rate' => 20.0000,
                'lines' => [[
                    'store_id' => $this->store->id,
                    'total_working_hours' => 4.00,
                ]],
            ]],
        ], $overrides);
    }

    private function service(): DailyPayEntryService
    {
        return app(DailyPayEntryService::class);
    }

    public function test_an_edit_carrying_a_stale_updated_at_is_refused(): void
    {
        $created = $this->service()->create($this->payload());
        $entry = DailyPayEntry::findOrFail($created['id']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('changed by someone else');

        $this->service()->edit($entry, $this->payload([
            'expected_updated_at' => '2020-01-01T00:00:00+00:00',
        ]));
    }

    public function test_an_edit_carrying_the_current_updated_at_goes_through(): void
    {
        $created = $this->service()->create($this->payload());
        $entry = DailyPayEntry::findOrFail($created['id']);

        $result = $this->service()->edit($entry, $this->payload([
            'expected_updated_at' => $entry->updated_at->toIso8601String(),
            'payments' => [['lines' => [['total_working_hours' => 9.00]]]],
        ]));

        $this->assertSame('9.00', (string) $result['payments'][0]['lines'][0]['total_working_hours']);
    }

    public function test_an_edit_without_the_guard_still_goes_through(): void
    {
        $created = $this->service()->create($this->payload());
        $entry = DailyPayEntry::findOrFail($created['id']);

        $result = $this->service()->edit($entry, $this->payload([
            'payments' => [['lines' => [['total_working_hours' => 7.00]]]],
        ]));

        $this->assertSame('7.00', (string) $result['payments'][0]['lines'][0]['total_working_hours']);
    }

    /**
     * The revision snapshot captures attachment URLs. Before soft deletes, the
     * edit that wrote the snapshot then hard-deleted the very rows it pointed
     * at, leaving the audit trail full of dead links.
     */
    public function test_replaced_notes_and_attachments_survive_the_edit_that_replaced_them(): void
    {
        $created = $this->service()->create(
            $this->payload([
                'payments' => [[
                    'lines' => [[
                        'notes' => [['body' => 'the original note', 'type' => null]],
                    ]],
                ]],
            ]),
            [],
            [],
            [0 => [0 => [UploadedFile::fake()->create('receipt.pdf', 12)]]],
        );

        $entry = DailyPayEntry::findOrFail($created['id']);

        $noteId = $created['payments'][0]['lines'][0]['notes'][0]['id'];
        $attachment = $created['payments'][0]['lines'][0]['attachments'][0];
        $this->assertNotNull($attachment, 'The line should have had a file attached.');

        $this->service()->edit($entry, $this->payload([
            'payments' => [['lines' => [['notes' => [['body' => 'a replacement note']]]]]],
        ]));

        // Gone from the live view...
        $this->assertNull(Note::find($noteId));
        $this->assertNull(Attachment::find($attachment['id']));

        // ...but still there, and so is the file the snapshot links to.
        $this->assertNotNull(Note::withTrashed()->find($noteId));
        $this->assertNotNull(Attachment::withTrashed()->find($attachment['id']));
        Storage::disk('public')->assertExists(Attachment::withTrashed()->find($attachment['id'])->path);

        // And the snapshot really does still name it.
        $snapshot = $entry->refresh()->revisions()->first()->snapshot;
        $this->assertSame('the original note', $snapshot['payments'][0]['lines'][0]['notes'][0]['body']);
    }

    /**
     * Pruning is the only thing that ever removes a file, and only long after
     * the row was replaced.
     */
    public function test_pruning_removes_only_attachments_past_the_retention_window(): void
    {
        $created = $this->service()->create(
            $this->payload(),
            [],
            [],
            [0 => [0 => [UploadedFile::fake()->create('old.pdf', 5)]]],
        );

        $entry = DailyPayEntry::findOrFail($created['id']);
        $attachmentId = $created['payments'][0]['lines'][0]['attachments'][0]['id'];

        $this->service()->edit($entry, $this->payload());

        $trashed = Attachment::withTrashed()->findOrFail($attachmentId);
        $path = $trashed->path;

        // Freshly replaced: well inside the window, so nothing goes.
        $this->artisan('attachments:prune', ['--older-than' => 180])->assertSuccessful();
        $this->assertNotNull(Attachment::withTrashed()->find($attachmentId));
        Storage::disk('public')->assertExists($path);

        // Age it past the window.
        $trashed->forceFill(['deleted_at' => now()->subDays(400)])->saveQuietly();

        $this->artisan('attachments:prune', ['--older-than' => 180])->assertSuccessful();
        $this->assertNull(Attachment::withTrashed()->find($attachmentId));
        Storage::disk('public')->assertMissing($path);
    }

    /** A payment may cover a location that is not in the replicated store list. */
    public function test_a_line_may_name_an_off_system_location_instead_of_a_store(): void
    {
        $result = $this->service()->create($this->payload([
            'payments' => [['lines' => [['store_id' => null, 'other_store' => 'Warehouse 5']]]],
        ]));

        $line = $result['payments'][0]['lines'][0];

        $this->assertNull($line['store_id']);
        $this->assertSame('Warehouse 5', $line['other_store']);
    }
}
