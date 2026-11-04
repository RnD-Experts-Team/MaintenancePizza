<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use App\Models\Concerns\HasUniqueName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place we buy from (Walmart, Amazon, Home Depot). Not a technician: a
 * supplier is never booked, rated or clocked -- only paid, or named as where
 * a part was bought.
 */
class Supplier extends Model
{
    use HasNotesAndAttachments, HasUniqueName, SoftDeletes;

    protected $fillable = ['name', 'phone', 'website', 'description', 'created_by'];

    /**
     * Payments made to this supplier directly.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Payment lines (to anyone) for parts bought here.
     *
     * @return HasMany<PaymentLine, $this>
     */
    public function boughtLines(): HasMany
    {
        return $this->hasMany(PaymentLine::class, 'bought_from_supplier_id');
    }

    /** @return HasMany<TechnicianPurchase, $this> */
    public function technicianPurchases(): HasMany
    {
        return $this->hasMany(TechnicianPurchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
