<?php

namespace App\Models;

use App\Enums\PartUsagePayer;
use App\Enums\StockMovementType;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One batch of stock moving. The ledger is append-only and is the source of
 * truth for what is on hand:
 *
 *     balance(part, location) = SUM(quantity * direction) over ALL lines
 *
 * with no filtering whatsoever. A mistake is corrected by writing an
 * equal-and-opposite `reversal` movement, never by editing or deleting.
 * `mistaken` is an audit flag for display and has NO effect on that sum:
 * excluding flagged rows AND writing a reversal would correct the same error
 * twice.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'moved_at',
        'type',
        'storage_location_id',
        'paid_by',
        'paid_by_technician_id',
        'body',
        'part_usage_id',
        'reverses_stock_movement_id',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
            'type' => StockMovementType::class,
            'paid_by' => PartUsagePayer::class,
            'mistaken' => 'boolean',
        ];
    }

    /** @return HasMany<StockMovementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovementLine::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /** @return BelongsTo<Technician, $this> */
    public function paidByTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'paid_by_technician_id');
    }

    /** @return BelongsTo<PartUsage, $this> */
    public function partUsage(): BelongsTo
    {
        return $this->belongsTo(PartUsage::class);
    }

    /** The movement this one undoes, when it is a reversal. */
    /** @return BelongsTo<StockMovement, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reverses_stock_movement_id');
    }

    /** The reversal that undid this movement, if one was written. */
    /** @return HasOne<StockMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'reverses_stock_movement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
