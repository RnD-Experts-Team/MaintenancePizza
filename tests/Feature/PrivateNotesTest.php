<?php

namespace Tests\Feature;

use App\Exports\Sheets\AttachmentsSheet;
use App\Exports\Sheets\TicketsSheet;
use App\Models\Issue;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * "Lock certain notes so they are not for overall view" -- notes and their
 * files, MOS only.
 *
 * Two reads of a ticket's notes: /notes (the normal ones) and /notes/all
 * (private ones too). The auth rules decide who may call which; every other
 * read that embeds notes leaves the private ones out.
 */
class PrivateNotesTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Ticket $ticket;

    private TicketIssue $oven;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->fakeAuthServer();

        $this->store = Store::factory()->create();
        $this->ticket = Ticket::factory()->for($this->store)->create();
        $this->oven = TicketIssue::factory()->for($this->ticket)->create([
            'issue_id' => Issue::factory()->create(['title' => 'Oven'])->id, 'other_title' => null,
        ]);
    }

    private function ticketUrl(string $path = ''): string
    {
        return "/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}{$path}";
    }

    private function writePrivateNote(string $body = 'Vendor quoted $900, do not tell the store'): int
    {
        return $this->post($this->ticketUrl("/issues/{$this->oven->id}/notes"), [
            'body' => $body,
            'is_private' => '1',
            'files' => [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.is_private', true)
            ->assertJsonPath('data.locker.id', $this->authUser->id)
            ->json('data.id');
    }

    public function test_the_normal_notes_leave_private_ones_out_and_all_notes_has_them_with_their_files(): void
    {
        $this->postJson($this->ticketUrl('/notes'), ['body' => 'Store says the oven is cold'], $this->headers())->assertCreated();
        $privateId = $this->writePrivateNote();

        $normal = $this->getJson($this->ticketUrl('/notes'), $this->headers())->assertOk()->json('data');
        $this->assertSame(['Store says the oven is cold'], array_column($normal, 'body'));
        $this->assertSame(['type' => 'ticket', 'id' => $this->ticket->id], $normal[0]['owner']);

        $all = collect($this->getJson($this->ticketUrl('/notes/all'), $this->headers())->assertOk()->json('data'));
        $this->assertCount(2, $all);
        $private = $all->firstWhere('id', $privateId);
        $this->assertTrue($private['is_private']);
        $this->assertSame(['type' => 'ticket_issue', 'id' => $this->oven->id], $private['owner']);
        $this->assertSame('quote.pdf', $private['attachments'][0]['original_name']);
    }

    public function test_reads_that_embed_notes_never_carry_a_private_one(): void
    {
        $this->writePrivateNote();

        $issues = $this->getJson($this->ticketUrl('/issues'), $this->headers())->assertOk();
        $this->assertSame([], $issues->json('data.0.notes'));
        $this->assertStringNotContainsString('Vendor quoted', $issues->getContent());

        $global = $this->getJson("/api/tickets/{$this->ticket->id}/issues", $this->headers())->assertOk();
        $this->assertStringNotContainsString('Vendor quoted', $global->getContent());
    }

    public function test_notes_on_records_are_found_under_their_record(): void
    {
        $technician = Technician::factory()->create();
        $this->oven->technicians()->attach($technician->id);

        $entryId = $this->postJson($this->ticketUrl('/attendance-entries'), [
            'ticket_issue_ids' => [$this->oven->id], 'technician_id' => $technician->id, 'start_clock' => '2026-10-06 09:00:00',
        ], $this->headers())->assertSuccessful()->json('data.id');

        $this->postJson($this->ticketUrl("/attendance-entries/{$entryId}/notes"), [
            'body' => 'Paid him cash', 'is_private' => true,
        ], $this->headers())->assertCreated();

        $this->assertSame([], $this->getJson($this->ticketUrl('/notes'), $this->headers())->json('data'));
        $this->assertSame(
            ['type' => 'attendance_entry', 'id' => $entryId],
            $this->getJson($this->ticketUrl('/notes/all'), $this->headers())->json('data.0.owner'),
        );
    }

    public function test_a_catalog_note_is_never_private(): void
    {
        $issue = Issue::factory()->create();

        $this->postJson("/api/issues/{$issue->id}/notes", ['body' => 'Spare igniters in the van', 'is_private' => true], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.is_private', false);
    }

    public function test_a_note_is_locked_and_unlocked_later(): void
    {
        $noteId = $this->postJson($this->ticketUrl('/notes'), ['body' => 'Manager was rude to the tech'], $this->headers())
            ->assertCreated()->json('data.id');

        $this->patchJson($this->ticketUrl("/notes/{$noteId}/privacy"), ['is_private' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.is_private', true)
            ->assertJsonPath('data.locker.id', $this->authUser->id);
        $this->assertSame([], $this->getJson($this->ticketUrl('/notes'), $this->headers())->json('data'));

        $this->patchJson($this->ticketUrl("/notes/{$noteId}/privacy"), ['is_private' => false], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.is_private', false);
        $this->assertCount(1, $this->getJson($this->ticketUrl('/notes'), $this->headers())->json('data'));
    }

    public function test_only_this_tickets_notes_can_be_locked_here(): void
    {
        $elsewhere = Ticket::factory()->for($this->store)->create();
        $foreign = $elsewhere->notes()->create(['body' => 'other ticket']);

        $this->patchJson($this->ticketUrl("/notes/{$foreign->id}/privacy"), ['is_private' => true], $this->headers())
            ->assertNotFound();

        // ...and the ticket must be the store's in the URL.
        $mine = $this->postJson($this->ticketUrl('/notes'), ['body' => 'x'], $this->headers())->json('data.id');
        $otherStore = Store::factory()->create();
        $this->patchJson("/api/stores/{$otherStore->store_number}/tickets/{$this->ticket->id}/notes/{$mine}/privacy", ['is_private' => true], $this->headers())
            ->assertNotFound();
    }

    public function test_private_notes_never_reach_the_export(): void
    {
        $this->post($this->ticketUrl('/final-note'), [
            'body' => 'internal learning', 'type' => 'what_we_learned', 'is_private' => '1',
            'files' => [UploadedFile::fake()->create('secret.pdf', 5)],
        ], $this->headers())->assertOk();
        $this->postJson($this->ticketUrl('/final-note'), ['body' => 'replaced the igniter', 'type' => 'final_notes'], $this->headers())->assertOk();

        $exported = (new TicketsSheet())->collection()->firstWhere('id', $this->ticket->id);
        $this->assertSame(['replaced the igniter'], $exported->notes->pluck('body')->all());

        $files = (new AttachmentsSheet())->collection()->pluck('original_name')->all();
        $this->assertNotContains('secret.pdf', $files);
    }
}
