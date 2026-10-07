<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\DailyPayEntry;
use App\Models\DailyPayLine;
use App\Models\DailyPayPayment;
use App\Models\Note;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * "For the daily pay I am a hundred percent sure there is an issue with notes
 * and attachments."
 *
 * There was: an edit rebuilds every payment and line, and it threw away every
 * note and file on the sheet unless the client re-uploaded the actual files --
 * which it never had. Notes it re-sent came back as NEW notes, with a new
 * author and date. Now the client lists what it keeps, by id, and those survive
 * the edit untouched apart from moving onto the rebuilt row.
 *
 * Over HTTP, through the real middleware, so the request's keep validation and
 * the acting user both take part.
 */
class DailyPayKeepNotesTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Technician $technician;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->fakeAuthServer();
        $this->technician = Technician::factory()->create();
        $this->store = Store::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $payment  merged into the single payment
     * @param  array<string, mixed>  $line     merged into its single line
     * @return array<string, mixed>
     */
    private function sheet(array $payment = [], array $line = [], array $extra = []): array
    {
        return array_merge([
            'date' => '2026-09-10',
            'payments' => [array_merge([
                'technician_id' => $this->technician->id,
                'hourly_payment_rate' => 20,
                'lines' => [array_merge([
                    'store_id' => $this->store->id,
                    'total_working_hours' => 4,
                ], $line)],
            ], $payment)],
        ], $extra);
    }

    /**
     * A sheet with a payment note + file and a line note (with its own file) + file.
     *
     * @return array<string, mixed> the created entry as the API returned it
     */
    private function createSheetWithNotesAndFiles(): array
    {
        return $this->post('/api/daily-pay-entries', $this->sheet(
            payment: [
                'notes' => [['body' => 'Paid in cash']],
                'files' => [UploadedFile::fake()->create('cash-slip.pdf', 10)],
            ],
            line: [
                'notes' => [['body' => 'Receipt from the supply house', 'files' => [UploadedFile::fake()->image('receipt.jpg')]]],
                'files' => [UploadedFile::fake()->image('oven-before.jpg'), UploadedFile::fake()->image('oven-after.jpg')],
            ],
        ), $this->headers())->assertCreated()->json('data');
    }

    public function test_an_edit_keeps_the_notes_and_files_it_lists_and_drops_the_rest(): void
    {
        $created = $this->createSheetWithNotesAndFiles();
        $entryId = $created['id'];
        $paymentNote = $created['payments'][0]['notes'][0];
        $paymentFile = $created['payments'][0]['attachments'][0];
        $lineNote = $created['payments'][0]['lines'][0]['notes'][0];
        [$keptFile, $droppedFile] = $created['payments'][0]['lines'][0]['attachments'];

        // Someone else edits the sheet: same author check below must still say
        // the ORIGINAL author, not the editor.
        $original = $this->authUser;
        $this->actAs(User::query()->create(['id' => 10, 'name' => 'Editor', 'email' => 'editor@example.com']));
        $this->travel(5)->minutes();

        $edited = $this->post("/api/daily-pay-entries/{$entryId}/edit", $this->sheet(
            payment: ['keep_note_ids' => [$paymentNote['id']], 'keep_attachment_ids' => [$paymentFile['id']]],
            line: [
                'total_working_hours' => 6,
                'keep_note_ids' => [$lineNote['id']],
                'keep_attachment_ids' => [$keptFile['id']],
                'notes' => [['body' => 'Added while editing']],
            ],
        ), $this->headers())->assertOk()->json('data');

        $payment = $edited['payments'][0];
        $line = $payment['lines'][0];

        // Same rows, not copies: ids, author and date all unchanged.
        $this->assertSame([$paymentNote['id']], array_column($payment['notes'], 'id'));
        $this->assertSame([$paymentFile['id']], array_column($payment['attachments'], 'id'));
        $this->assertContains($lineNote['id'], array_column($line['notes'], 'id'));
        $keptLineNote = collect($line['notes'])->firstWhere('id', $lineNote['id']);
        $this->assertSame($original->id, $keptLineNote['created_by']);
        $this->assertSame($lineNote['created_at'], $keptLineNote['created_at']);
        // The kept note brought its own file along.
        $this->assertSame('receipt.jpg', $keptLineNote['attachments'][0]['original_name']);
        // The new note is there, by the editor.
        $this->assertSame(10, collect($line['notes'])->firstWhere('body', 'Added while editing')['created_by']);

        // The line kept one of its two files; the other is gone from the sheet
        // but still on disk for the revision snapshot.
        $this->assertSame([$keptFile['id']], array_column($line['attachments'], 'id'));
        $this->assertNull(Attachment::find($droppedFile['id']));
        $this->assertNotNull(Attachment::withTrashed()->find($droppedFile['id']));

        // And they now hang off the REBUILT rows.
        $this->assertSame(DailyPayLine::class, Note::find($lineNote['id'])->notable_type);
        $this->assertSame($line['id'], (int) Note::find($lineNote['id'])->notable_id);
        $this->assertSame($payment['id'], (int) Attachment::find($paymentFile['id'])->attachable_id);
    }

    public function test_an_edit_that_keeps_nothing_still_removes_everything(): void
    {
        $created = $this->createSheetWithNotesAndFiles();

        $edited = $this->post("/api/daily-pay-entries/{$created['id']}/edit", $this->sheet(), $this->headers())
            ->assertOk()->json('data');

        $this->assertSame([], $edited['payments'][0]['notes']);
        $this->assertSame([], $edited['payments'][0]['attachments']);
        $this->assertSame([], $edited['payments'][0]['lines'][0]['notes']);
        $this->assertSame([], $edited['payments'][0]['lines'][0]['attachments']);
    }

    public function test_a_note_from_another_pay_sheet_cannot_be_kept(): void
    {
        $other = $this->createSheetWithNotesAndFiles();
        $mine = $this->post('/api/daily-pay-entries', $this->sheet(extra: ['date' => '2026-09-11']), $this->headers())
            ->assertCreated()->json('data');

        $foreignNoteId = $other['payments'][0]['lines'][0]['notes'][0]['id'];

        $this->post("/api/daily-pay-entries/{$mine['id']}/edit", $this->sheet(
            line: ['keep_note_ids' => [$foreignNoteId]],
            extra: ['date' => '2026-09-11'],
        ), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments.0.lines.0.keep_note_ids.0');

        // And it did not move.
        $this->assertSame($other['payments'][0]['lines'][0]['id'], (int) Note::find($foreignNoteId)->notable_id);
    }

    public function test_a_line_note_cannot_be_kept_as_a_payment_note(): void
    {
        $created = $this->createSheetWithNotesAndFiles();
        $lineNoteId = $created['payments'][0]['lines'][0]['notes'][0]['id'];

        $this->post("/api/daily-pay-entries/{$created['id']}/edit", $this->sheet(
            payment: ['keep_note_ids' => [$lineNoteId]],
        ), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments.0.keep_note_ids.0');
    }

    public function test_the_same_file_cannot_be_kept_twice(): void
    {
        $technician2 = Technician::factory()->create();
        $created = $this->createSheetWithNotesAndFiles();
        $fileId = $created['payments'][0]['lines'][0]['attachments'][0]['id'];

        $payload = $this->sheet(line: ['keep_attachment_ids' => [$fileId]]);
        $payload['payments'][] = [
            'technician_id' => $technician2->id,
            'lines' => [['store_id' => $this->store->id, 'total_working_hours' => 1, 'keep_attachment_ids' => [$fileId]]],
        ];

        $this->post("/api/daily-pay-entries/{$created['id']}/edit", $payload, $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments.1.lines.0.keep_attachment_ids.0');
    }

    public function test_nothing_can_be_kept_on_a_new_sheet(): void
    {
        $this->post('/api/daily-pay-entries', $this->sheet(line: ['keep_note_ids' => [1]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments.0.lines.0.keep_note_ids.0');
    }

    /**
     * The guard compares updated_at -- and an edit that left the date alone
     * used to leave updated_at alone too, so a second editor holding the old
     * value sailed through and overwrote the first.
     */
    public function test_a_second_edit_from_a_stale_screen_is_refused_even_when_the_date_did_not_change(): void
    {
        $created = $this->post('/api/daily-pay-entries', $this->sheet(), $this->headers())->assertCreated()->json('data');
        $loadedAt = DailyPayEntry::findOrFail($created['id'])->updated_at->toIso8601String();

        $this->travel(2)->seconds();
        $this->post("/api/daily-pay-entries/{$created['id']}/edit", $this->sheet(
            line: ['total_working_hours' => 5],
            extra: ['expected_updated_at' => $loadedAt],
        ), $this->headers())->assertOk();

        $this->travel(2)->seconds();
        $this->post("/api/daily-pay-entries/{$created['id']}/edit", $this->sheet(
            line: ['total_working_hours' => 9],
            extra: ['expected_updated_at' => $loadedAt],
        ), $this->headers())->assertStatus(409);

        $this->assertSame(
            '5.00',
            (string) DailyPayLine::query()->where('daily_pay_entry_id', $created['id'])->value('total_working_hours'),
        );
    }

    public function test_the_revision_snapshot_still_shows_the_sheet_as_it_was(): void
    {
        $created = $this->createSheetWithNotesAndFiles();
        $lineNote = $created['payments'][0]['lines'][0]['notes'][0];

        $this->post("/api/daily-pay-entries/{$created['id']}/edit", $this->sheet(
            line: ['keep_note_ids' => [$lineNote['id']]],
        ), $this->headers())->assertOk();

        $snapshot = DailyPayEntry::findOrFail($created['id'])->revisions()->first()->snapshot;

        $this->assertSame('Receipt from the supply house', $snapshot['payments'][0]['lines'][0]['notes'][0]['body']);
        $this->assertCount(2, $snapshot['payments'][0]['lines'][0]['attachments']);
        $this->assertSame('Paid in cash', $snapshot['payments'][0]['notes'][0]['body']);
        $this->assertSame(DailyPayPayment::class, Note::withTrashed()->where('body', 'Paid in cash')->value('notable_type'));
    }
}
