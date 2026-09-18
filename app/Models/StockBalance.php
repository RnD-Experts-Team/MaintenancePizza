<?php

namespace App\Models;

use Database\Factories\StockBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How many of one part are at one location. A cache of the ledger, written
 * only by StockService inside the same transaction as the movement that
 * changed it. Never write to it from anywhere else.
 */
class StockBalance extends Model
{
    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory;

    // Every column here is written only by StockService. The address is
    // user-entered but lives on stock_balance_places rather than on this row,
    // so a rebuild of the cache can no longer take it with it.
    protected $fillable = ['part_id', 'storage_location_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * Where inside the location this part sits -- one row per level that has a
     * value, so "Shelf C, Row 8" is two rows and a part with only a column
     * recorded is one.
     *
     * An empty set means nobody has said, which is a different answer from
     * "nowhere" and must not be rendered as a dash.
     *
     * @return HasMany<StockBalancePlace, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(StockBalancePlace::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }
}
