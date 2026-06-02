<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * Technicians in this category. Deleting the category nulls their
     * category_id (DB-level) rather than removing the technicians.
     *
     * @return HasMany<Technician, $this>
     */
    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }
}
