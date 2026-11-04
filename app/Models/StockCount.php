<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A stock count sheet for one storage location (or one place in it): every
 * part with what the system expects, and what somebody really counted. A
 * draft can be saved and finished later; applying it writes ONE stock_count
 * movement for the differences and freezes the values, so an applied count
 * stays as the report. Status: draft | applied.
 *
 * Its free-text notes column is `notes_text` because `notes` is the
 * polymorphic notes relation every record has.
 */
class StockCount extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'storage_location_id',
        'storage_place_value_id',
        'status',
        'started_at',
        'applied_at',
        'applied_by',
        'missing_value',
        'extra_value',
        'notes_text',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'applied_at' => 'datetime',
            'missing_value' => 'decimal:2',
            'extra_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class)->withTrashed();
    }

    /** @return BelongsTo<StoragePlaceValue, $this> */
    public function placeValue(): BelongsTo
    {
        return $this->belongsTo(StoragePlaceValue::class, 'storage_place_value_id');
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /** @return HasOne<StockMovement, $this> */
    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
