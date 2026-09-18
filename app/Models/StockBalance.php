<?php

namespace App\Models;

use Database\Factories\StockBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many of one part are at one location. A cache of the ledger, written
 * only by StockService inside the same transaction as the movement that
 * changed it. Never write to it from anywhere else.
 */
class StockBalance extends Model
{
    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory;

    // storage_slot_id is user-entered and is NOT part of the cached figure --
    // see the migration. Everything else here is written only by StockService.
    protected $fillable = ['part_id', 'storage_location_id', 'storage_slot_id', 'quantity'];

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

    /** Where inside the location this part sits. Null when nobody has said.
     *  @return BelongsTo<StorageSlot, $this> */
    public function storageSlot(): BelongsTo
    {
        return $this->belongsTo(StorageSlot::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }
}
