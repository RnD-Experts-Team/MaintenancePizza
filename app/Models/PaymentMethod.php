<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How a payment was made (Cash, Zelle, Company card, ...): an editable list,
 * names unique ignoring capitals and repeated spaces. One in use by a
 * payment cannot be deleted -- rename it instead.
 */
class PaymentMethod extends Model
{
    use HasUniqueName;

    protected $fillable = ['name', 'sort'];

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
