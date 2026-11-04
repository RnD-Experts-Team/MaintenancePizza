<?php

namespace App\Models\Concerns;

use App\Support\NameKey;

/**
 * For the catalogs whose names must be unique ignoring capitals and repeated
 * spaces (technicians, technician categories, parts, suppliers).
 *
 * Every save tidies the name as typed (trimmed, inner spaces collapsed) and
 * keeps `name_key` in step with it. The unique index on `name_key` is the
 * real guard; the UniqueName validation rule only turns the clash into a
 * readable 422 before the database has to refuse it.
 */
trait HasUniqueName
{
    public static function bootHasUniqueName(): void
    {
        static::saving(function ($model) {
            if ($model->isDirty('name') || $model->name_key === null) {
                $model->name = NameKey::tidy($model->name);
                $model->name_key = NameKey::of($model->name);
            }
        });
    }
}
