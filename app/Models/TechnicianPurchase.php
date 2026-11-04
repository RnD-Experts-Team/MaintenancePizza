<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a technician bought with his own money (from the trip page or the
 * ticket), waiting to be paid back. The receipts are its attachments. It is
 * paid once a parts payment line claims it (`payment_line_id`); marking that
 * payment mistaken releases it again.
 */
class TechnicianPurchase extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'technician_id',
        'trip_id',
        'trip_stop_id',
        'ticket_issue_id',
        'part_id',
        'description',
        'quantity',
        'total_cost',
        'supplier_id',
        'serial_number',
        'bought_at',
        'payment_line_id',
        'mistaken',
        'created_by',
    ];

    /** A purchase on an issue is a change to the issue. */
    protected $touches = ['ticketIssue'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'bought_at' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<TripStop, $this> */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(TripStop::class, 'trip_stop_id');
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<PaymentLine, $this> */
    public function paymentLine(): BelongsTo
    {
        return $this->belongsTo(PaymentLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPaid(): bool
    {
        return $this->payment_line_id !== null;
    }

    /**
     * Not mistaken and not yet paid back.
     *
     * @param  Builder<TechnicianPurchase>  $query
     */
    public function scopeUnpaid(Builder $query): void
    {
        $query->where('mistaken', false)->whereNull('payment_line_id');
    }
}
