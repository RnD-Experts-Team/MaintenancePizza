<?php

namespace App\Services;

use App\Models\StorageLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The storage locations catalog — the physical places parts are kept. Follows
 * the same shape as the catalogs in CatalogService (list / create / delete /
 * restore + a presenter); kept separate because it belongs to the storage
 * module rather than to the ticket reference data.
 *
 * Locations soft-delete. A retired location keeps its stock history, and its
 * balances stay readable, because the ledger references it by id forever.
 */
class StorageLocationService
{
    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private CatalogService $catalog,
    ) {
    }

    public function list(?string $trashed, int $perPage): LengthAwarePaginator
    {
        /** @var Builder<StorageLocation> $query */
        $query = StorageLocation::query()->with('creator')->orderBy('name');

        match ($trashed) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };

        return $query->paginate($perPage)->through(fn (StorageLocation $l) => $this->present($l));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $location = new StorageLocation($data);
        $location->created_by = Auth::id();
        $location->save();

        return $this->present($location->load('creator'));
    }

    public function delete(StorageLocation $location): void
    {
        $location->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(StorageLocation $location): array
    {
        $location->restore();

        return $this->present($location->load('creator'));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(StorageLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'code' => $location->code,
            'address' => $location->address,
            'notes' => $this->notes->presentMany($location),
            'attachments' => $this->attachments->presentMany($location),
            'created_by' => $location->created_by,
            'creator' => $location->relationLoaded('creator') && $location->creator
                ? $this->catalog->presentUser($location->creator)
                : null,
            'created_at' => $location->created_at,
            'updated_at' => $location->updated_at,
            'deleted_at' => $location->deleted_at,
        ];
    }
}
