<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Part;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * All controlled reference catalogs (issues, technicians, categories, parts):
 * listing with the ?trashed flag, creation, soft/hard delete, restore, and
 * presentation. Standalone — depends on no other service.
 */
class CatalogService
{
    /**
     * Loaded on every read that presents a catalog item. The presenters always
     * emitted notes and attachments, but nothing loaded them, so a note added
     * to an issue or technician saved fine and then came back as `null` on the
     * next list -- it looked lost.
     */
    private const CATALOG_LOADS = [
        'creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
    ];

    /**
     * Issues also say how many troubleshooting guides they have -- enough for
     * the new-ticket window to know it must show them; the guides themselves
     * are read from the issue's troubleshooting page.
     */
    private const ISSUE_LOADS = self::CATALOG_LOADS;

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private TechnicianAbilityService $abilities,
    ) {}

    // ------------------------------------------------------------------ Issues

    /**
     * Only guides with steps count: one without asks nothing of the store.
     *
     * @return array<string, \Closure>
     */
    private function guideCount(): array
    {
        return ['troubleshootingGuides' => fn ($q) => $q->whereHas('steps')];
    }

    public function listIssues(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Issue::query()->with(self::ISSUE_LOADS)->withCount($this->guideCount())->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Issue $i) => $this->presentIssue($i));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createIssue(array $data): array
    {
        return $this->presentIssue($this->persist(new Issue($data))->load(self::ISSUE_LOADS)->loadCount($this->guideCount()));
    }

    public function deleteIssue(Issue $issue): void
    {
        $issue->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreIssue(Issue $issue): array
    {
        $issue->restore();

        return $this->presentIssue($issue->load(self::ISSUE_LOADS)->loadCount($this->guideCount()));
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updateIssue(Issue $issue, array $data): array
    {
        $issue->fill($data)->save();

        return $this->presentIssue($issue->load(self::ISSUE_LOADS)->loadCount($this->guideCount()));
    }

    // ------------------------------------------------------------- Technicians

    /** A technician is shown with their category and the stores they cover. */
    private const TECHNICIAN_LOADS = ['category', 'coverageStores', ...self::CATALOG_LOADS];

    public function listTechnicians(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Technician::query()->with(self::TECHNICIAN_LOADS)->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Technician $t) => $this->presentTechnician($t));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createTechnician(array $data): array
    {
        $technician = DB::transaction(function () use ($data) {
            $technician = $this->persist(new Technician($data));
            $this->syncCoverage($technician, $data);

            return $technician;
        });

        return $this->showTechnician($technician);
    }

    /**
     * One technician, with everything the technician page shows about them.
     *
     * @return array<string, mixed>
     */
    public function showTechnician(Technician $technician): array
    {
        return $this->presentTechnician($technician->load(self::TECHNICIAN_LOADS));
    }

    /**
     * Coverage is the whole list of stores, replaced when sent.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCoverage(Technician $technician, array $data): void
    {
        if (!array_key_exists('coverage_stores', $data)) {
            return;
        }

        $ids = Store::query()->whereIn('store_number', (array) $data['coverage_stores'])->pluck('id')->all();
        $technician->coverageStores()->sync($ids);
    }

    /**
     * Soft delete. Their "call first" pins go with them (see
     * TechnicianAbilityService::clearPins); stars and notes stay for a restore.
     */
    public function deleteTechnician(Technician $technician): void
    {
        DB::transaction(function () use ($technician) {
            $this->abilities->clearPins($technician);
            $technician->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreTechnician(Technician $technician): array
    {
        $technician->restore();

        return $this->showTechnician($technician);
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updateTechnician(Technician $technician, array $data): array
    {
        DB::transaction(function () use ($technician, $data) {
            $technician->fill($data)->save();
            $this->syncCoverage($technician, $data);
        });

        return $this->showTechnician($technician);
    }

    // -------------------------------------------------------------- Categories

    public function listCategories(int $perPage): LengthAwarePaginator
    {
        return Category::query()->with(self::CATALOG_LOADS)->withCount('technicians')->latest()
            ->paginate($perPage)->through(fn (Category $c) => $this->presentCategory($c));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCategory(array $data): array
    {
        return $this->presentCategory($this->persist(new Category($data))->load(self::CATALOG_LOADS));
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updateCategory(Category $category, array $data): array
    {
        $category->fill($data)->save();

        return $this->presentCategory($category->load(self::CATALOG_LOADS)->loadCount('technicians'));
    }

    public function deleteCategory(Category $category): void
    {
        // Hard delete; the FK is nullOnDelete so its technicians survive.
        $category->delete();
    }

    // ------------------------------------------------------------------- Parts

    public function listParts(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Part::query()->with(self::CATALOG_LOADS)->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Part $p) => $this->presentPart($p));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPart(array $data): array
    {
        return $this->presentPart($this->persist(new Part($data))->load(self::CATALOG_LOADS));
    }

    public function deletePart(Part $part): void
    {
        $part->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restorePart(Part $part): array
    {
        $part->restore();

        return $this->presentPart($part->load(self::CATALOG_LOADS));
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updatePart(Part $part, array $data): array
    {
        $part->fill($data)->save();

        return $this->presentPart($part->load(self::CATALOG_LOADS));
    }

    // ------------------------------------------------------------- Presenters

    /**
     * @return array<string, mixed>
     */
    public function presentIssue(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'description' => $issue->description,
            // Guides to go through before opening a ticket for it (null when
            // not counted on this read).
            'troubleshooting_guides_count' => array_key_exists('troubleshooting_guides_count', $issue->getAttributes())
                ? (int) $issue->troubleshooting_guides_count
                : null,
            'notes' => $this->notes->presentMany($issue),
            'attachments' => $this->attachments->presentMany($issue),
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator
                ? $this->presentUser($issue->creator)
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
            'deleted_at' => $issue->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTechnician(Technician $technician): array
    {
        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'phone' => $technician->phone,
            'location' => $technician->location,
            'coverage_notes' => $technician->coverage_notes,
            // The stores they can cover (null when not loaded on this read).
            'coverage_stores' => $technician->relationLoaded('coverageStores')
                ? $technician->coverageStores->map(fn (Store $s) => ['id' => $s->id, 'store_number' => $s->store_number])->values()->all()
                : null,
            'category_id' => $technician->category_id,
            'category' => $technician->relationLoaded('category') && $technician->category
                ? $this->presentCategory($technician->category)
                : null,
            'notes' => $this->notes->presentMany($technician),
            'attachments' => $this->attachments->presentMany($technician),
            'created_by' => $technician->created_by,
            'creator' => $technician->relationLoaded('creator') && $technician->creator
                ? $this->presentUser($technician->creator)
                : null,
            'created_at' => $technician->created_at,
            'updated_at' => $technician->updated_at,
            'deleted_at' => $technician->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'technicians_count' => $category->technicians_count ?? null,
            'notes' => $this->notes->presentMany($category),
            'attachments' => $this->attachments->presentMany($category),
            'created_by' => $category->created_by,
            'creator' => $category->relationLoaded('creator') && $category->creator
                ? $this->presentUser($category->creator)
                : null,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPart(Part $part): array
    {
        return [
            'id' => $part->id,
            'name' => $part->name,
            'description' => $part->description,
            'notes' => $this->notes->presentMany($part),
            'attachments' => $this->attachments->presentMany($part),
            'created_by' => $part->created_by,
            'creator' => $part->relationLoaded('creator') && $part->creator
                ? $this->presentUser($part->creator)
                : null,
            'created_at' => $part->created_at,
            'updated_at' => $part->updated_at,
            'deleted_at' => $part->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Stamp the acting user and save.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function persist($model)
    {
        $model->created_by = Auth::id();
        $model->save();

        return $model;
    }

    /**
     * Apply the ?trashed=with|only flag to a soft-deletable query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function trashed(Builder $query, ?string $trashed): Builder
    {
        return match ($trashed) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query,
        };
    }
}
