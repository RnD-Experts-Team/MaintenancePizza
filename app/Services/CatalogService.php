<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Part;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * All controlled reference catalogs (issues, technicians, categories, parts):
 * listing with the ?trashed flag, creation, soft/hard delete, restore, and
 * presentation. Standalone — depends on no other service.
 */
class CatalogService
{
    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {}

    // ------------------------------------------------------------------ Issues

    public function listIssues(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Issue::query()->with('creator')->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Issue $i) => $this->presentIssue($i));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createIssue(array $data): array
    {
        return $this->presentIssue($this->persist(new Issue($data))->load('creator'));
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

        return $this->presentIssue($issue->load('creator'));
    }

    // ------------------------------------------------------------- Technicians

    public function listTechnicians(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Technician::query()->with(['category', 'creator'])->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Technician $t) => $this->presentTechnician($t));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createTechnician(array $data): array
    {
        return $this->presentTechnician($this->persist(new Technician($data))->load(['category', 'creator']));
    }

    public function deleteTechnician(Technician $technician): void
    {
        $technician->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreTechnician(Technician $technician): array
    {
        $technician->restore();

        return $this->presentTechnician($technician->load(['category', 'creator']));
    }

    // -------------------------------------------------------------- Categories

    public function listCategories(int $perPage): LengthAwarePaginator
    {
        return Category::query()->with('creator')->withCount('technicians')->latest()
            ->paginate($perPage)->through(fn (Category $c) => $this->presentCategory($c));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCategory(array $data): array
    {
        return $this->presentCategory($this->persist(new Category($data))->load('creator'));
    }

    public function deleteCategory(Category $category): void
    {
        // Hard delete; the FK is nullOnDelete so its technicians survive.
        $category->delete();
    }

    // ------------------------------------------------------------------- Parts

    public function listParts(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Part::query()->with('creator')->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Part $p) => $this->presentPart($p));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPart(array $data): array
    {
        return $this->presentPart($this->persist(new Part($data))->load('creator'));
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

        return $this->presentPart($part->load('creator'));
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
