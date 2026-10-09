<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Store;
use App\Models\TroubleshootingFix;
use App\Models\TroubleshootingGuide;
use App\Models\TroubleshootingStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Troubleshooting guides: several per catalog issue, one per specific
 * problem, shown to the store before it opens a ticket for that issue -- and
 * in a library everyone can browse. Also the record of problems a guide fixed,
 * which never become tickets.
 */
class TroubleshootingService
{
    /** Everything a presented guide shows. */
    private const GUIDE_LOADS = ['steps.attachments.creator', 'attachments.creator', 'editor'];

    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * @param  array<int, array{id?: int|null, body?: string|null}>  $steps
     */
    public function create(Issue $issue, string $title, array $steps, ?string $linkUrl): TroubleshootingGuide
    {
        return DB::transaction(function () use ($issue, $title, $steps, $linkUrl) {
            $guide = new TroubleshootingGuide(['issue_id' => $issue->id]);
            $guide->version = 0;

            return $this->write($guide, $title, $steps, $linkUrl);
        });
    }

    /**
     * Replace a guide's title, steps and link. Every save is a new version, so
     * a ticket can say exactly which steps the manager tried.
     *
     * Steps sent with their id are kept (and so are their files); steps sent
     * without one are new; steps left out are removed, with their files.
     *
     * @param  array<int, array{id?: int|null, body?: string|null}>  $steps
     */
    public function update(TroubleshootingGuide $guide, string $title, array $steps, ?string $linkUrl): TroubleshootingGuide
    {
        return DB::transaction(fn () => $this->write($guide, $title, $steps, $linkUrl));
    }

    /**
     * @param  array<int, array{id?: int|null, body?: string|null}>  $steps
     */
    private function write(TroubleshootingGuide $guide, string $title, array $steps, ?string $linkUrl): TroubleshootingGuide
    {
        $guide->title = trim($title);
        $guide->link_url = $linkUrl ?: null;
        $guide->version = (int) $guide->version + 1;
        $guide->updated_by = Auth::id();
        $guide->save();

        $existing = $guide->steps()->get()->keyBy('id');
        $kept = [];
        $position = 0;

        // The order sent is the order shown. (validated() rebuilds nested
        // arrays field by field, so its keys are not always in order.)
        ksort($steps);

        foreach ($steps as $step) {
            $body = trim((string) ($step['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            $row = isset($step['id']) ? $existing->get((int) $step['id']) : null;
            $row ??= new TroubleshootingStep(['troubleshooting_guide_id' => $guide->id]);
            $row->fill(['position' => $position++, 'body' => $body])->save();
            $kept[] = $row->id;
        }

        $existing->except($kept)->each(fn (TroubleshootingStep $gone) => $this->removeStep($gone));

        return $this->loaded($guide);
    }

    /**
     * Delete a guide. Its files and its steps' files are soft-deleted like
     * every attachment -- a ticket's snapshot names files by what they were
     * called, so it stays readable.
     */
    public function remove(TroubleshootingGuide $guide): void
    {
        DB::transaction(function () use ($guide) {
            $guide->steps()->get()->each(fn (TroubleshootingStep $step) => $this->removeStep($step));
            $guide->attachments()->delete();
            $guide->delete();
        });
    }

    private function removeStep(TroubleshootingStep $step): void
    {
        $step->attachments()->delete();
        $step->delete();
    }

    /**
     * A file on a guide or on one of its steps.
     */
    public function removeFile(Model $owner, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $owner->getMorphClass() || (int) $attachment->attachable_id !== (int) $owner->getKey()) {
            abort(404);
        }

        $attachment->delete();
    }

    /**
     * A problem the store fixed with troubleshooting: logged, never a ticket.
     */
    public function logFix(Store $store, Issue $issue, ?TroubleshootingGuide $guide): TroubleshootingFix
    {
        $fix = new TroubleshootingFix([
            'store_id' => $store->id,
            'issue_id' => $issue->id,
            'troubleshooting_guide_id' => $guide?->id,
            'snapshot' => $this->snapshot($issue, $guide, 'fixed'),
        ]);
        $fix->forceFill(['created_by' => Auth::id()])->save();

        return $fix;
    }

    public function loaded(TroubleshootingGuide $guide): TroubleshootingGuide
    {
        return $guide->load(self::GUIDE_LOADS)->loadCount(['fixes', 'triedOn']);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TroubleshootingGuide $guide): array
    {
        return [
            'id' => $guide->id,
            'issue_id' => $guide->issue_id,
            'title' => $guide->title,
            'steps' => $guide->steps->map(fn (TroubleshootingStep $step) => [
                'id' => $step->id,
                'position' => $step->position,
                'body' => $step->body,
                'attachments' => $this->attachments->presentMany($step) ?? [],
            ])->values()->all(),
            'link_url' => $guide->link_url,
            'version' => (int) $guide->version,
            'attachments' => $this->attachments->presentMany($guide) ?? [],
            'updated_by' => $guide->updated_by,
            'editor' => $guide->relationLoaded('editor') && $guide->editor
                ? ['id' => $guide->editor->id, 'name' => $guide->editor->name]
                : null,
            'updated_at' => $guide->updated_at,
            // How it has gone for stores: fixed without a ticket, or tried and
            // a ticket opened anyway.
            'fixed_count' => (int) ($guide->fixes_count ?? 0),
            'not_fixed_count' => (int) ($guide->tried_on_count ?? 0),
        ];
    }

    /**
     * One issue and all its guides -- the troubleshooting page.
     *
     * @return array<string, mixed>
     */
    public function forIssue(Issue $issue): array
    {
        $guides = $issue->troubleshootingGuides()->with(self::GUIDE_LOADS)->withCount(['fixes', 'triedOn'])->get();

        return [
            'issue_id' => $issue->id,
            'title' => $issue->title,
            'description' => $issue->description,
            'guides' => $guides->map(fn (TroubleshootingGuide $g) => $this->present($g))->values()->all(),
        ];
    }

    /**
     * Every catalog issue that has a guide, alphabetically -- the library.
     * Guides come as titles and counts; the issue page has the steps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function library(): array
    {
        return Issue::query()
            ->whereHas('troubleshootingGuides')
            ->with(['troubleshootingGuides' => fn ($q) => $q->withCount(['steps', 'fixes', 'triedOn'])])
            ->orderBy('title')
            ->get()
            ->map(fn (Issue $issue) => [
                'issue_id' => $issue->id,
                'title' => $issue->title,
                'description' => $issue->description,
                'guides' => $issue->troubleshootingGuides->map(fn (TroubleshootingGuide $g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'steps_count' => (int) $g->steps_count,
                    'version' => (int) $g->version,
                    'fixed_count' => (int) $g->fixes_count,
                    'not_fixed_count' => (int) $g->tried_on_count,
                    'updated_at' => $g->updated_at,
                ])->values()->all(),
            ])
            ->all();
    }

    /**
     * Guides that actually ask something of the store (at least one step).
     * Only these gate opening a ticket.
     *
     * @param  array<int, int>  $issueIds
     * @return Builder<TroubleshootingGuide>
     */
    public function gatingGuides(array $issueIds): Builder
    {
        return TroubleshootingGuide::query()->whereIn('issue_id', $issueIds)->whereHas('steps');
    }

    /**
     * Troubleshooting as the manager saw it -- frozen onto the ticket issue or
     * the fix, because guides change later. `guides_shown` is every guide the
     * page listed, so "none of these" still says what "these" were.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Issue $issue, ?TroubleshootingGuide $guide, string $outcome): array
    {
        $guide?->loadMissing(['steps.attachments', 'attachments']);

        return [
            'outcome' => $outcome,
            'guide_id' => $guide?->id,
            'guide_title' => $guide?->title,
            'version' => $guide ? (int) $guide->version : null,
            'steps' => $guide
                ? $guide->steps->map(fn (TroubleshootingStep $s) => [
                    'body' => $s->body,
                    'files' => $s->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name])->values()->all(),
                ])->values()->all()
                : [],
            'link_url' => $guide?->link_url,
            'files' => $guide
                ? $guide->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name])->values()->all()
                : [],
            'guides_shown' => $this->gatingGuides([$issue->id])->orderBy('title')->orderBy('id')->get(['id', 'title', 'version'])
                ->map(fn (TroubleshootingGuide $g) => ['id' => $g->id, 'title' => $g->title, 'version' => (int) $g->version])
                ->values()->all(),
            'confirmed_by' => Auth::id(),
        ];
    }
}
