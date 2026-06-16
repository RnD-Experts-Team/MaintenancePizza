<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
