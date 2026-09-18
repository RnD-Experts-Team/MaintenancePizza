<?php

namespace App\Services;

use App\Models\StorageLocation;
use App\Models\StockBalancePlace;
use App\Models\StoragePlaceLevel;
use App\Models\StoragePlaceValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    /* ------------------------------------------------- Place levels & values */

    /*
     * How a location addresses the space inside it.
     *
     * Storage A declares its LEVELS -- Shelf, Row, Column, Section -- and each
     * level declares its VALUES. A part then carries at most one value per
     * level, and every level is optional, so a thing that lives in a column and
     * nothing else says exactly that.
     *
     * Values are declared rather than typed freehand so "C" cannot also exist
     * as "c" and as "Shelf C", which is the only thing that makes "what is on
     * Shelf C?" answerable. The setup that buys is kept cheap by letting a new
     * value be declared from inside the picker.
     */

    /**
     * The levels of one location, each with its declared values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function placeLevels(StorageLocation $location, bool $withTrashed = false): array
    {
        return $location->placeLevels()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->with(['placeValues' => fn ($q) => $withTrashed ? $q->withTrashed() : $q])
            ->get()
            ->map(fn (StoragePlaceLevel $level) => $this->presentPlaceLevel($level))
            ->all();
    }

    public function createPlaceLevel(StorageLocation $location, array $data): array
    {
        $level = new StoragePlaceLevel($data);
        $level->storage_location_id = $location->id;
        $level->created_by = Auth::id();
        $level->save();

        return $this->presentPlaceLevel($level->load('placeValues'));
    }

    public function updatePlaceLevel(StoragePlaceLevel $level, array $data): array
    {
        $level->fill($data)->save();

        return $this->presentPlaceLevel($level->refresh()->load('placeValues'));
    }

    /**
     * Retiring a level stops it being offered AND drops the addresses that used
     * it, in one transaction.
     *
     * Leaving those rows behind is what the slots feature did: the column went
     * on pointing at a retired row while the API rendered null, which is two
     * stories about one fact. A part keeps its other levels and its quantity is
     * never touched; it simply stops claiming a level that no longer exists.
     */
    public function deletePlaceLevel(StoragePlaceLevel $level): void
    {
        DB::transaction(function () use ($level) {
            StockBalancePlace::query()->where('storage_place_level_id', $level->id)->delete();
            $level->placeValues()->delete();
            $level->delete();
        });
    }

    public function createPlaceValue(StoragePlaceLevel $level, array $data): array
    {
        $value = new StoragePlaceValue($data);
        $value->storage_place_level_id = $level->id;
        $value->created_by = Auth::id();
        $value->save();

        return $this->presentPlaceValue($value);
    }

    public function updatePlaceValue(StoragePlaceValue $value, array $data): array
    {
        $value->fill($data)->save();

        return $this->presentPlaceValue($value->refresh());
    }

    /** Same rule as a level: the addresses that used it go with it, so nothing
     *  is left pointing at something that will never be shown again. */
    public function deletePlaceValue(StoragePlaceValue $value): void
    {
        DB::transaction(function () use ($value) {
            StockBalancePlace::query()->where('storage_place_value_id', $value->id)->delete();
            $value->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPlaceLevel(StoragePlaceLevel $level): array
    {
        return [
            'id' => $level->id,
            'storage_location_id' => $level->storage_location_id,
            'name' => $level->name,
            'sort_order' => $level->sort_order,
            // Null, not [], when the relation was not loaded -- "we did not ask"
            // and "there are none" are different and the client can tell.
            'values' => $level->relationLoaded('placeValues')
                ? $level->placeValues->map(fn (StoragePlaceValue $v) => $this->presentPlaceValue($v))->all()
                : null,
            'created_at' => $level->created_at,
            'updated_at' => $level->updated_at,
            'deleted_at' => $level->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPlaceValue(StoragePlaceValue $value): array
    {
        return [
            'id' => $value->id,
            'storage_place_level_id' => $value->storage_place_level_id,
            'value' => $value->value,
            'sort_order' => $value->sort_order,
            'created_at' => $value->created_at,
            'updated_at' => $value->updated_at,
            'deleted_at' => $value->deleted_at,
        ];
    }
}
