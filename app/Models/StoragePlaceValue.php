<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One declared value on a level -- "C" on Shelf, "8" on Row.
 *
 * Values are declared rather than typed freehand so that "C" cannot also exist
 * as "c" and "Shelf C", which is what makes "what is on Shelf C?" answerable at
 * all. The setup cost that buys is paid back by letting a new value be declared
 * from inside the picker, so tagging a part onto a brand-new shelf is still one
 * flow rather than two.
 */
class StoragePlaceValue extends Model
{
    protected $fillable = ['storage_place_level_id', 'value', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<StoragePlaceLevel, $this> */
    public function placeLevel(): BelongsTo
    {
        return $this->belongsTo(StoragePlaceLevel::class, 'storage_place_level_id');
    }

    /** @return HasMany<StockBalancePlace, $this> */
    public function stockBalancePlaces(): HasMany
    {
        return $this->hasMany(StockBalancePlace::class, 'storage_place_value_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
