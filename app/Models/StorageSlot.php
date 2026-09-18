<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named place inside a storage location -- a shelf, a bay, a drawer.
 *
 * Each location defines its own, so nobody has to fit a van and a depot into
 * one vocabulary. A slot says where a part LIVES; it is not a stock dimension,
 * and quantities remain per (part, location).
 *
 * Soft-deletes for the same reason locations do: a retired slot stops being
 * offered without orphaning the stock that referenced it.
 */
class StorageSlot extends Model
{
    use SoftDeletes;

    protected $fillable = ['storage_location_id', 'name', 'code', 'sort_order'];

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

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
