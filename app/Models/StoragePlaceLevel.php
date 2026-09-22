<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One axis of a location's addressing scheme -- "Shelf", "Row", "Column".
 *
 * Each location names its own and as many as it wants, so a van gets "Rack"
 * and a depot gets "Aisle / Bay / Level" without either being forced into the
 * other's vocabulary.
 *
 * Levels are FLAT, not a tree. "Row 8" is a label that means the same thing on
 * every shelf; it is not a child of Shelf C. Nesting them would multiply the
 * setup work by the number of shelves for no gain in what can be said.
 *
 */
class StoragePlaceLevel extends Model
{
    protected $fillable = ['storage_location_id', 'name', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /**
     * The declared values on this level -- A, B, C.
     *
     * NAMED `placeValues` TO MATCH ITS ROUTE PARAMETER. Laravel's scoped
     * bindings derive the relation from the parameter name via
     * Str::plural(Str::camel($param)), so `{placeValue}` resolves to
     * `placeValues()`. The slots feature shipped broken for exactly this
     * reason: its parameter was `{storageSlot}` and its relation was `slots()`.
     *
     * @return HasMany<StoragePlaceValue, $this>
     */
    public function placeValues(): HasMany
    {
        return $this->hasMany(StoragePlaceValue::class)->orderBy('sort_order')->orderBy('value');
    }

    /** @return HasMany<StockBalancePlace, $this> */
    public function stockBalancePlaces(): HasMany
    {
        return $this->hasMany(StockBalancePlace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
