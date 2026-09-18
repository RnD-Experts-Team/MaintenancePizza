<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\StorageLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StorageLocation extends Model
{
    /** @use HasFactory<StorageLocationFactory> */
    use HasFactory, HasNotesAndAttachments, SoftDeletes;

    protected $fillable = ['name', 'code', 'address'];

    /**
     * How this location addresses the space inside it -- Shelf, Row, Column.
     *
     * NAMED `placeLevels` TO MATCH ITS ROUTE PARAMETER `{placeLevel}`. Laravel
     * resolves a scoped binding's relation as Str::plural(Str::camel($param)),
     * so a mismatch here is a 500 on every nested PATCH and DELETE -- which is
     * exactly how the slots feature this replaces shipped broken.
     *
     * @return HasMany<StoragePlaceLevel, $this>
     */
    public function placeLevels(): HasMany
    {
        return $this->hasMany(StoragePlaceLevel::class)->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<StockMovementLine, $this> */
    public function stockMovementLines(): HasMany
    {
        return $this->hasMany(StockMovementLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
