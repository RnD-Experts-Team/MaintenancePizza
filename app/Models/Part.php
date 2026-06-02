<?php

namespace App\Models;

use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['name'];

    /** @return HasMany<PartUsage, $this> */
    public function partUsages(): HasMany
    {
        return $this->hasMany(PartUsage::class);
    }
}
