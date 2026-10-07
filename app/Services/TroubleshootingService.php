<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Issue;
use App\Models\TroubleshootingGuide;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Troubleshooting guides: one per catalog issue, shown to the store before it
 * opens a ticket for that issue, and in a library everyone can browse.
 */
class TroubleshootingService
{
    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * Create or replace an issue's guide. Every save is a new version, so a
     * ticket can say exactly which steps the manager confirmed trying.
     *
     * @param  array<int, string>  $steps
     */
    public function upsert(Issue $issue, array $steps, ?string $linkUrl): TroubleshootingGuide
    {
        return DB::transaction(function () use ($issue, $steps, $linkUrl) {
            $guide = TroubleshootingGuide::query()->firstOrNew(['issue_id' => $issue->id]);
            $guide->steps = array_values(array_filter(array_map('trim', $steps), fn ($s) => $s !== ''));
            $guide->link_url = $linkUrl ?: null;
            $guide->version = $guide->exists ? $guide->version + 1 : 1;
            $guide->updated_by = Auth::id();
            $guide->save();

            return $guide->load(['attachments.creator', 'editor']);
        });
    }

    public function remove(Issue $issue): void
    {
        $issue->troubleshootingGuide()->first()?->delete();
    }

    /**
     * A file on a guide. Soft-deleted like every attachment; a ticket's
     * snapshot names files by what they were called, so it stays readable.
     */
    public function removeFile(TroubleshootingGuide $guide, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $guide->getMorphClass() || (int) $attachment->attachable_id !== (int) $guide->id) {
            abort(404);
        }

        $attachment->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(?TroubleshootingGuide $guide): ?array
    {
        if ($guide === null) {
            return null;
        }

        return [
            'id' => $guide->id,
            'issue_id' => $guide->issue_id,
            'steps' => array_values($guide->steps ?? []),
            'link_url' => $guide->link_url,
            'version' => (int) $guide->version,
            'attachments' => $this->attachments->presentMany($guide) ?? [],
            'updated_by' => $guide->updated_by,
            'editor' => $guide->relationLoaded('editor') && $guide->editor
                ? ['id' => $guide->editor->id, 'name' => $guide->editor->name]
                : null,
            'updated_at' => $guide->updated_at,
        ];
    }

    /**
     * The guide as a manager saw it when confirming -- frozen onto the ticket
     * issue, because the guide itself may be edited later.
     *
     * @return array<string, mixed>
     */
    public function snapshot(TroubleshootingGuide $guide): array
    {
        $guide->loadMissing('attachments');

        return [
            'guide_id' => $guide->id,
            'version' => (int) $guide->version,
            'steps' => array_values($guide->steps ?? []),
            'link_url' => $guide->link_url,
            'files' => $guide->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name])->values()->all(),
            'confirmed_by' => Auth::id(),
        ];
    }

    /**
     * Every catalog issue that has a guide, alphabetically -- the library.
     *
     * @return array<int, array<string, mixed>>
     */
    public function library(): array
    {
        return Issue::query()
            ->whereHas('troubleshootingGuide')
            ->with(['troubleshootingGuide.attachments.creator', 'troubleshootingGuide.editor'])
            ->orderBy('title')
            ->get()
            ->map(fn (Issue $issue) => [
                'issue_id' => $issue->id,
                'title' => $issue->title,
                'description' => $issue->description,
                'troubleshooting' => $this->present($issue->troubleshootingGuide),
            ])
            ->all();
    }
}
