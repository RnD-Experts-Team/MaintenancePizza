<?php

namespace App\Models;

use App\Enums\PaymentLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One line of a payment: what it paid for (type) and where the money was
 * spent -- exactly one of store_id / other_store / storage_location_id (the
 * last only for parts going onto a shelf).
 *
 * The columns used depend on the type: hourly (hours, rate,
 * hours_overridden, trip sessions), parts (part, quantity, unit_cost,
 * bought_from), gas (miles), inspection (issue_inspection_id). `amount` is
 * always what the line costs, computed by the payment service.
 */
class PaymentLine extends Model
{
    protected $fillable = [
        'payment_id',
        'type',
        'store_id',
        'other_store',
        'storage_location_id',
        'description',
        'amount',
        'hours',
        'rate',
        'hours_overridden',
        'part_id',
        'quantity',
        'unit_cost',
        'bought_from_supplier_id',
        'miles',
        'issue_inspection_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentLineType::class,
            'amount' => 'decimal:2',
            'hours' => 'decimal:2',
            'rate' => 'decimal:4',
            'hours_overridden' => 'boolean',
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'miles' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class)->withTrashed();
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function boughtFrom(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'bought_from_supplier_id')->withTrashed();
    }

    /** @return BelongsTo<IssueInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(IssueInspection::class, 'issue_inspection_id');
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'payment_line_ticket_issue')->withTimestamps();
    }

    /**
     * The sessions an hourly line paid, each with the minutes it paid for.
     *
     * @return BelongsToMany<TripSession, $this>
     */
    public function tripSessions(): BelongsToMany
    {
        return $this->belongsToMany(TripSession::class, 'payment_line_trip_session')
            ->withPivot('paid_minutes')
            ->withTimestamps();
    }

    /**
     * The Bought movement a parts line into storage wrote.
     *
     * @return HasOne<StockMovement, $this>
     */
    public function stockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class)->where('type', 'bought');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<TechnicianPurchase, $this> */
    public function technicianPurchases(): HasMany
    {
        return $this->hasMany(TechnicianPurchase::class);
    }

    /** @return HasMany<PartUnit, $this> */
    public function partUnits(): HasMany
    {
        return $this->hasMany(PartUnit::class);
    }
}
