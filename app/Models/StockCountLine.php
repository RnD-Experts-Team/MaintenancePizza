<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part on a stock count: what the system expected, what was counted
 * (null = not counted yet) and, once applied, the unit cost the difference
 * was valued at.
 */
class StockCountLine extends Model
{
    protected $fillable = ['stock_count_id', 'part_id', 'expected', 'counted', 'unit_cost'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected' => 'decimal:2',
            'counted' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    /**
     * counted − expected, or null while not counted.
     */
    public function difference(): ?string
    {
        if ($this->counted === null) {
            return null;
        }

        return number_format((float) $this->counted - (float) $this->expected, 2, '.', '');
    }
}
