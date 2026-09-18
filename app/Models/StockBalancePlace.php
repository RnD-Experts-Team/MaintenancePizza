<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a part's address: this balance, on this level, has this value.
 *
 * A part's full address is however many of these rows exist for it -- so
 * "Shelf C, Row 8, Column 5" is three rows, and a part that only has a column
 * recorded is one. Every level is optional, and a missing row means "nobody has
 * said", which is a different answer from "nowhere" and must not be rendered as
 * a dash.
 *
 * Not a stock dimension. The quantity lives on the balance; this only says
 * where to walk.
 */
class StockBalancePlace extends Model
{
    protected $fillable = [
        'stock_balance_id',
        'storage_place_level_id',
        'storage_place_value_id',
    ];

    /** @return BelongsTo<StockBalance, $this> */
    public function stockBalance(): BelongsTo
    {
        return $this->belongsTo(StockBalance::class);
    }

    /** @return BelongsTo<StoragePlaceLevel, $this> */
    public function placeLevel(): BelongsTo
    {
        return $this->belongsTo(StoragePlaceLevel::class, 'storage_place_level_id');
    }

    /** @return BelongsTo<StoragePlaceValue, $this> */
    public function placeValue(): BelongsTo
    {
        return $this->belongsTo(StoragePlaceValue::class, 'storage_place_value_id');
    }
}
