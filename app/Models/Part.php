<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use App\Models\Concerns\HasUniqueName;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A part in the catalog, with what people look for on a shelf (brand,
 * model, part number, the unit it is counted in). With `track_serials` on,
 * every piece is its own PartUnit; otherwise it is counted by quantity.
 */
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory, HasNotesAndAttachments, HasUniqueName, SoftDeletes;

    public const UNITS = ['each', 'box', 'pack', 'ft', 'm', 'gal', 'l', 'kg', 'lb', 'set'];

    protected $fillable = ['name', 'description', 'brand', 'model', 'part_number', 'unit', 'track_serials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'track_serials' => 'boolean',
        ];
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<PartUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(PartUnit::class);
    }

    /** @return HasMany<PaymentLine, $this> */
    public function paymentLines(): HasMany
    {
        return $this->hasMany(PaymentLine::class);
    }

    /**
     * How many are on hand, at one location or across all of them. Reads the
     * cached balances; see StockService for how those are kept true.
     */
    public function onHand(?int $storageLocationId = null): string
    {
        $query = $this->stockBalances();

        if ($storageLocationId !== null) {
            $query->where('storage_location_id', $storageLocationId);
        }

        return number_format((float) $query->sum('quantity'), 2, '.', '');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
