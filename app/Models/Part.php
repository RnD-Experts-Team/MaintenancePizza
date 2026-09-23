<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory, HasNotesAndAttachments, SoftDeletes;

    protected $fillable = ['name'];

    /** @return HasMany<PartUsage, $this> */
    public function partUsages(): HasMany
    {
        return $this->hasMany(PartUsage::class);
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
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
