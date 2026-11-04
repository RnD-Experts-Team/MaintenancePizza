<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\PaymentLine;
use App\Models\PaymentRevision;
use App\Models\Store;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Notes and files on a payment and on each of its lines (docs/MAINTENANCE.md
 * §4), over HTTP: as many as wanted on create, and on edit the payment's own
 * stay unless removed while a line keeps only what it names.
 */
class PaymentNotesAndFilesTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Technician $tom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        Storage::fake('public');

        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->tom = Technician::factory()->create(['name' => 'Tom', 'hourly_rate' => '20']);
    }

    /**
     * A multipart payment with two notes and a file on the payment, and two
     * lines with their own notes and files.
     *
     * @return array<string, mixed>
     */
    private function createWithExtras(): array
    {
        return $this->post('/api/payments', [
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'notes' => ['Paid at the store', '   ', 'Receipt in the office'],
            'files' => [UploadedFile::fake()->create('invoice.pdf', 20)],
            'lines' => [
                [
                    'type' => 'fixed',
                    'store_id' => $this->store->id,
                    'amount' => '40',
                    'notes' => ['Oven fixed', 'Came back twice'],
                    'files' => [UploadedFile::fake()->image('before.jpg'), UploadedFile::fake()->image('after.jpg')],
                ],
                [
                    'type' => 'other',
                    'store_id' => $this->store->id,
                    'amount' => '5',
                    'description' => 'Parking',
                    'notes' => ['Ticket from the lot'],
                ],
            ],
        ], $this->headers())->assertCreated()->json('data');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $data  the payment as read
     * @param  array<string, mixed>  $extra
     */
    private function edit(array $data, array $lines, array $extra = []): TestResponse
    {
        $this->travel(5)->seconds();

        return $this->postJson("/api/payments/{$data['id']}/edit", $extra + [
            'expected_updated_at' => $data['updated_at'],
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'lines' => $lines,
        ], $this->headers());
    }

    public function test_create_takes_many_notes_and_files_on_the_payment_and_on_each_line(): void
    {
        $data = $this->createWithExtras();

        $this->assertSame('45.00', $data['total']);
        $this->assertSame(['Paid at the store', 'Receipt in the office'], array_column($data['notes'], 'body'));
        $this->assertCount(1, $data['attachments']);

        $this->assertSame(['Oven fixed', 'Came back twice'], array_column($data['lines'][0]['notes'], 'body'));
        $this->assertSame(['before.jpg', 'after.jpg'], array_column($data['lines'][0]['attachments'], 'original_name'));
        $this->assertSame(['Ticket from the lot'], array_column($data['lines'][1]['notes'], 'body'));
        $this->assertSame([], $data['lines'][1]['attachments']);

        // The payment's notes are not the lines' notes.
        $this->assertSame(3, Note::query()->where('notable_type', (new PaymentLine)->getMorphClass())->count());

        $this->getJson("/api/payments/{$data['id']}", $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data.lines.0.notes')
            ->assertJsonCount(2, 'data.lines.0.attachments');
    }

    public function test_a_json_body_takes_line_notes_and_the_old_single_note_still_works(): void
    {
        $data = $this->postJson('/api/payments', [
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'note' => 'One note',
            'notes' => ['Another'],
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '10', 'notes' => ['On the line', '']]],
        ], $this->headers())->assertCreated()->json('data');

        $this->assertSame(['One note', 'Another'], array_column($data['notes'], 'body'));
        $this->assertSame(['On the line'], array_column($data['lines'][0]['notes'], 'body'));
    }

    public function test_edit_keeps_what_each_line_names_removes_the_rest_and_adds_new_ones(): void
    {
        $data = $this->createWithExtras();
        $oven = $data['lines'][0];
        $parking = $data['lines'][1];
        [$fixedNote, $twiceNote] = array_column($oven['notes'], 'id');
        [$before, $after] = array_column($oven['attachments'], 'id');
        $paymentNote = $data['notes'][0]['id'];

        $edited = $this->edit($data, [
            // The lines swap places; each keeps what it names.
            [
                'type' => 'other',
                'store_id' => $this->store->id,
                'amount' => '6',
                'description' => 'Parking',
                // The parking line takes over the oven line's "after" photo.
                'keep_note_ids' => [$parking['notes'][0]['id']],
                'keep_attachment_ids' => [$after],
            ],
            [
                'type' => 'fixed',
                'store_id' => $this->store->id,
                'amount' => '40',
                'keep_note_ids' => [$fixedNote],
                'keep_attachment_ids' => [$before],
                'notes' => ['Checked again'],
            ],
        ], [
            'notes' => ['Edited'],
            'remove_note_ids' => [$paymentNote],
            'remove_attachment_ids' => [$data['attachments'][0]['id']],
        ])->assertOk()->json('data');

        $this->assertSame(['Ticket from the lot'], array_column($edited['lines'][0]['notes'], 'body'));
        $this->assertSame([$after], array_column($edited['lines'][0]['attachments'], 'id'));
        $this->assertSame(['Oven fixed', 'Checked again'], array_column($edited['lines'][1]['notes'], 'body'));
        $this->assertSame([$before], array_column($edited['lines'][1]['attachments'], 'id'));

        $this->assertSame(['Receipt in the office', 'Edited'], array_column($edited['notes'], 'body'));
        $this->assertSame([], $edited['attachments']);

        // Nothing is hard-deleted: what no line kept is soft-deleted.
        $this->assertSoftDeleted('notes', ['id' => $twiceNote]);
        $this->assertSoftDeleted('notes', ['id' => $paymentNote]);
        $this->assertSoftDeleted('attachments', ['id' => $data['attachments'][0]['id']]);
        $this->assertNotSoftDeleted('attachments', ['id' => $before]);

        // The revision still shows the payment as it was.
        $snapshot = PaymentRevision::query()->sole()->snapshot;
        $this->assertSame(['Oven fixed', 'Came back twice'], array_column($snapshot['lines'][0]['notes'], 'body'));
    }

    public function test_an_edit_that_names_nothing_drops_the_line_extras_but_keeps_the_payments_own(): void
    {
        $data = $this->createWithExtras();

        $edited = $this->edit($data, [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '45']])
            ->assertOk()->json('data');

        $this->assertSame([], $edited['lines'][0]['notes']);
        $this->assertSame([], $edited['lines'][0]['attachments']);
        $this->assertCount(2, $edited['notes']);
        $this->assertCount(1, $edited['attachments']);
        $this->assertSame(0, Note::query()->where('notable_type', (new PaymentLine)->getMorphClass())->count());
        $this->assertSame(0, Attachment::query()->where('attachable_type', (new PaymentLine)->getMorphClass())->count());
    }

    public function test_edit_refuses_ids_that_are_not_this_payments(): void
    {
        $data = $this->createWithExtras();
        $other = $this->createWithExtras();
        $line = fn (array $extra = []) => $extra + ['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '45'];

        // A note of another payment's line.
        $this->edit($data, [$line(['keep_note_ids' => [$other['lines'][0]['notes'][0]['id']]])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.keep_note_ids']);

        // The payment's own note is not a line's.
        $this->edit($data, [$line(['keep_note_ids' => [$data['notes'][0]['id']]])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.keep_note_ids']);

        // A file kept by two lines.
        $file = $data['lines'][0]['attachments'][0]['id'];
        $this->edit($data, [$line(['keep_attachment_ids' => [$file]]), $line(['keep_attachment_ids' => [$file]])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.keep_attachment_ids']);

        // Removing another payment's note or file.
        $this->edit($data, [$line()], ['remove_note_ids' => [$other['notes'][0]['id']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remove_note_ids']);
        $this->edit($data, [$line()], ['remove_attachment_ids' => [$other['lines'][0]['attachments'][0]['id']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remove_attachment_ids']);

        // Nothing changed.
        $this->getJson("/api/payments/{$data['id']}", $this->headers())
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonCount(2, 'data.lines.0.notes')
            ->assertJsonCount(2, 'data.notes');
    }

    public function test_keep_and_remove_are_refused_on_create(): void
    {
        $this->postJson('/api/payments', [
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'remove_note_ids' => [1],
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '10', 'keep_note_ids' => [1]]],
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remove_note_ids', 'lines.0.keep_note_ids']);
    }

    public function test_a_mistaken_payment_keeps_its_line_notes_and_files(): void
    {
        $data = $this->createWithExtras();

        $this->postJson("/api/payments/{$data['id']}/mistaken", ['reason' => 'Paid twice'], $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data.lines.0.notes')
            ->assertJsonCount(2, 'data.lines.0.attachments');
    }
}
