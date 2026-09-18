<?php

namespace App\Services;

use App\Models\StorageLocation;
use App\Models\StorageSlot;
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

    /* ------------------------------------------------------------- Slots */

    /**
     * The named places inside one location.
     *
     * @return array<int, array<string, mixed>>
     */
    public function slots(StorageLocation $location, bool $withTrashed = false): array
    {
        return $location->slots()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->get()
            ->map(fn (StorageSlot $slot) => $this->presentSlot($slot))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createSlot(StorageLocation $location, array $data): array
    {
        $slot = new StorageSlot($data);
        $slot->storage_location_id = $location->id;
        $slot->created_by = Auth::id();
        $slot->save();

        return $this->presentSlot($slot);
    }

    /**
     * Slots ARE editable, unlike locations -- a shelf gets relabelled far more
     * often than a depot gets renamed, and a typo in "Section 5" is not worth
     * retiring and recreating.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSlot(StorageSlot $slot, array $data): array
    {
        $slot->fill($data)->save();

        return $this->presentSlot($slot->refresh());
    }

    /** Retiring a slot leaves the stock where it is -- the FK nulls rather than
     *  restricting, so we simply stop claiming to know which shelf. */
    public function deleteSlot(StorageSlot $slot): void
    {
        $slot->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSlot(StorageSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'storage_location_id' => $slot->storage_location_id,
            'name' => $slot->name,
            'code' => $slot->code,
            'sort_order' => $slot->sort_order,
            'created_at' => $slot->created_at,
            'updated_at' => $slot->updated_at,
            'deleted_at' => $slot->deleted_at,
        ];
    }

}
