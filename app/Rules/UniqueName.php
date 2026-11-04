<?php

namespace App\Rules;

use App\Support\NameKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * No two technicians, technician categories, parts or suppliers may share a
 * name ignoring capitals and repeated spaces ("Ahmed  Ali" = "ahmed ali").
 *
 * Compares NameKey::of(value) against the table's `name_key`, INCLUDING
 * soft-deleted rows: the unique index covers them, so restoring a deleted
 * "Ahmed Ali" next to a new one could never succeed anyway -- better to say
 * so when the second one is typed. The index stays the real guard; this only
 * turns the clash into a readable 422.
 *
 *   new UniqueName('technicians', 'technician')                 on create
 *   new UniqueName('technicians', 'technician', $technician->id) on update
 */
class UniqueName implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $thing,
        private int|string|null $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || NameKey::of($value) === '') {
            return;
        }

        $taken = DB::table($this->table)
            ->where('name_key', NameKey::of($value))
            ->when($this->ignoreId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($taken) {
            $fail("There is already a {$this->thing} called ".NameKey::tidy($value).'.');
        }
    }
}
