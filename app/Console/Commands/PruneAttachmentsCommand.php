<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only thing in this system that ever unlinks a file.
 *
 * Attachments soft-delete rather than disappearing, because daily-pay revision
 * snapshots record the URLs of what they replaced -- hard-deleting on edit left
 * the audit trail full of dead links. The consequence is that nothing was ever
 * cleaned up: every file ever replaced is still on disk.
 *
 * This is the other half of that bargain. After a retention window long enough
 * for the snapshots to have stopped mattering, the row is force-deleted and its
 * file removed. Pruning inside the window is refused by the window itself --
 * `--older-than` is measured from `deleted_at`, so a file is only ever eligible
 * once it has BEEN replaced and then sat unreferenced for that long.
 *
 * Deliberately NOT scheduled. It deletes files irreversibly, and how long this
 * business needs its audit trail is not a decision this command should make on
 * its own. Add it to routes/console.php when that window has been agreed.
 */
class PruneAttachmentsCommand extends Command
{
    protected $signature = 'attachments:prune
        {--older-than=365 : Only prune attachments replaced more than this many days ago}
        {--dry-run : List what would be pruned without deleting anything}';

    protected $description = 'Permanently remove long-replaced attachments and their files';

    public function handle(): int
    {
        $days = (int) $this->option('older-than');

        if ($days < 1) {
            $this->error('--older-than must be at least 1 day. Pruning everything replaced today would defeat the point of soft-deleting them.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = Attachment::onlyTrashed()->where('deleted_at', '<', $cutoff);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("Nothing replaced before {$cutoff->toDateString()}. Nothing to prune.");

            return self::SUCCESS;
        }

        $this->info(
            ($dryRun ? 'Would prune ' : 'Pruning ')
            . "{$total} attachment(s) replaced before {$cutoff->toDateString()}."
        );

        $filesRemoved = 0;
        $filesKept = 0;
        $filesMissing = 0;
        $rowsRemoved = 0;

        $query->orderBy('id')->chunkById(200, function ($attachments) use (
            $dryRun, &$filesRemoved, &$filesKept, &$filesMissing, &$rowsRemoved
        ) {
            foreach ($attachments as $attachment) {
                $path = $attachment->path;

                // Paths come from $file->store(), which hashes the name, so two
                // rows sharing one are not something this system produces. The
                // check is here anyway because the failure mode is silent and
                // permanent: unlinking a file a LIVE attachment still points at
                // would break that one with nothing to show for it.
                $sharedWith = Attachment::withTrashed()
                    ->where('path', $path)
                    ->where('id', '!=', $attachment->id)
                    ->count();

                if ($dryRun) {
                    $this->line("  {$attachment->id}  {$attachment->original_name}  ({$path})"
                        . ($sharedWith > 0 ? '  [file kept: shared]' : ''));
                    continue;
                }

                // The file first, then the row. The other order can leave a row
                // gone and a file orphaned with nothing left pointing at it --
                // and an orphan on disk is invisible, where a failed unlink with
                // the row intact is simply retried on the next run.
                if ($sharedWith > 0) {
                    $filesKept++;
                } elseif (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    $filesRemoved++;
                } else {
                    // Already gone: still remove the row, or it is pruned again
                    // on every future run forever.
                    $filesMissing++;
                }

                DB::transaction(fn () => $attachment->forceDelete());
                $rowsRemoved++;
            }
        });

        if ($dryRun) {
            $this->warn('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        $this->table(
            ['Rows removed', 'Files deleted', 'Files already gone', 'Files kept (shared)'],
            [[$rowsRemoved, $filesRemoved, $filesMissing, $filesKept]]
        );

        if ($filesMissing > 0) {
            $this->warn("{$filesMissing} file(s) were already missing from disk. Their rows were removed so they stop being re-examined.");
        }

        return self::SUCCESS;
    }
}
