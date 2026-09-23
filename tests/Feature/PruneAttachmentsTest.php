<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `attachments:prune`.
 *
 * DailyPayEditSafetyTest already covers the happy path end to end -- replace an
 * attachment, watch it survive, age it past the window, watch it go. This
 * covers the edges that path does not reach, because this command is the only
 * thing in the system that deletes a file and there is no undo.
 */
class PruneAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function replacedAttachment(string $path, int $daysAgo): Attachment
    {
        Storage::disk('public')->put($path, 'contents');

        $attachment = Attachment::factory()->create([
            'attachable_type' => Ticket::class,
            'attachable_id' => Ticket::factory()->create()->id,
            'path' => $path,
        ]);

        $attachment->delete();
        $attachment->forceFill(['deleted_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $attachment;
    }

    /** A live attachment is not a candidate at all, however old. */
    public function test_it_never_touches_a_live_attachment(): void
    {
        Storage::disk('public')->put('attachments/live.pdf', 'contents');
        $live = Attachment::factory()->create([
            'attachable_type' => Ticket::class,
            'attachable_id' => Ticket::factory()->create()->id,
            'path' => 'attachments/live.pdf',
            'created_at' => now()->subDays(4000),
        ]);

        $this->artisan('attachments:prune', ['--older-than' => 1])->assertSuccessful();

        $this->assertNotNull(Attachment::find($live->id));
        Storage::disk('public')->assertExists('attachments/live.pdf');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $old = $this->replacedAttachment('attachments/old.pdf', 400);

        $this->artisan('attachments:prune', ['--older-than' => 180, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertNotNull(Attachment::withTrashed()->find($old->id));
        Storage::disk('public')->assertExists('attachments/old.pdf');
    }

    /**
     * The window is what protects the audit trail, so a zero or negative one is
     * refused rather than obeyed -- it would prune everything replaced today and
     * defeat the reason these rows soft-delete at all.
     */
    public function test_it_refuses_a_window_of_less_than_a_day(): void
    {
        $old = $this->replacedAttachment('attachments/old.pdf', 400);

        $this->artisan('attachments:prune', ['--older-than' => 0])->assertFailed();

        $this->assertNotNull(Attachment::withTrashed()->find($old->id));
        Storage::disk('public')->assertExists('attachments/old.pdf');
    }

    /**
     * A file already gone from disk still gets its row removed -- otherwise it
     * is re-examined on every run from now until the end of time.
     */
    public function test_a_row_whose_file_is_already_gone_is_still_removed(): void
    {
        $old = $this->replacedAttachment('attachments/vanished.pdf', 400);
        Storage::disk('public')->delete('attachments/vanished.pdf');

        $this->artisan('attachments:prune', ['--older-than' => 180])->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($old->id));
    }

    /**
     * Two rows on one path: the row goes, the file stays. Paths are hashed by
     * `$file->store()` so this should never arise -- but the failure mode is a
     * live attachment silently losing its file, permanently, so it is guarded.
     */
    public function test_it_keeps_a_file_another_attachment_still_points_at(): void
    {
        $old = $this->replacedAttachment('attachments/shared.pdf', 400);

        $survivor = Attachment::factory()->create([
            'attachable_type' => Ticket::class,
            'attachable_id' => Ticket::factory()->create()->id,
            'path' => 'attachments/shared.pdf',
        ]);

        $this->artisan('attachments:prune', ['--older-than' => 180])->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($old->id), 'The replaced row should go.');
        $this->assertNotNull(Attachment::find($survivor->id));
        Storage::disk('public')->assertExists('attachments/shared.pdf');
    }

    public function test_it_reports_when_there_is_nothing_to_do(): void
    {
        $this->artisan('attachments:prune', ['--older-than' => 180])
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();
    }

    /** Several at once, and only the ones past the window. */
    public function test_it_prunes_only_what_is_past_the_window(): void
    {
        $stale = $this->replacedAttachment('attachments/stale.pdf', 400);
        $recent = $this->replacedAttachment('attachments/recent.pdf', 10);

        $this->artisan('attachments:prune', ['--older-than' => 180])->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($stale->id));
        Storage::disk('public')->assertMissing('attachments/stale.pdf');

        $this->assertNotNull(Attachment::withTrashed()->find($recent->id));
        Storage::disk('public')->assertExists('attachments/recent.pdf');
    }
}
