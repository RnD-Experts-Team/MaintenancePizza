<?php

namespace App\Models;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\PartUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartUsage extends Model
{
    /** @use HasFactory<PartUsageFactory> */
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'part_id',
        'quantity',
        'unit_cost',
        'cost',
        'source',
        'paid_by',
        'paid_by_technician_id',
        'storage_location_id',
        'returned_quantity',
        'returned_to_storage_location_id',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            // GROSS total outlay = quantity * unit_cost. Computed once by
            // WorkflowRecordService when the row is written, and never
            // re-derived on read. TicketService::applyFilters() sums this
            // column in part_cost_single_gt / part_cost_total_gt, so making it
            // net-of-returns would silently change both filters. Use netCost()
            // when you want the after-returns figure.
            'cost' => 'decimal:2',
            'source' => PartUsageSource::class,
            'paid_by' => PartUsagePayer::class,
            'returned_quantity' => 'decimal:2',
            'mistaken' => 'boolean',
        ];
    }

    /** How many were actually consumed, after anything handed back to storage. */
    public function netQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) ($this->returned_quantity ?? 0), 2);
    }

    /**
     * What the payer is actually out of pocket, after returns. Presentation and
     * reimbursement only — nothing writes this. See the note on `cost`.
     */
    public function netCost(): string
    {
        $returned = bcmul(
            (string) ($this->returned_quantity ?? 0),
            (string) ($this->unit_cost ?? 0),
            4
        );

        return bcsub((string) $this->cost, $returned, 2);
    }

    /** Whether this usage should move stock out of a location. */
    public function drawsFromStorage(): bool
    {
        return $this->source === PartUsageSource::FromStorage
            && $this->storage_location_id !== null
            && bccomp((string) $this->quantity, '0', 2) > 0;
    }

    /** Whether this usage should move stock back into a location. */
    public function returnsToStorage(): bool
    {
        return $this->returned_to_storage_location_id !== null
            && bccomp((string) ($this->returned_quantity ?? 0), '0', 2) > 0;
    }

    /** Whether someone other than us paid, and is therefore owed the money back. */
    public function isReimbursable(): bool
    {
        return $this->paid_by !== PartUsagePayer::Us;
    }

    /**
     * The payments that have reimbursed this receipt, with the amount each
     * allowed.
     *
     * @return BelongsToMany<DailyPayPayment, $this>
     */
    public function dailyPayPayments(): BelongsToMany
    {
        return $this->belongsToMany(DailyPayPayment::class, 'daily_pay_payment_part_usage')
            ->withPivot(['daily_pay_line_id', 'store_id', 'amount'])
            ->withTimestamps();
    }

    /**
     * Whether whoever paid for this has had it back through a pay sheet. Being
     * on a sheet IS being paid.
     *
     * A part we bought ourselves is never reimbursed to anyone, so it has
     * nothing to pay rather than being perpetually unpaid. Derived, never
     * stored; null when the claims are not loaded.
     */
    public function paymentStatus(): ?PaymentStatus
    {
        if ($this->mistaken || ! $this->isReimbursable()) {
            return PaymentStatus::NotPayable;
        }

        if (! $this->relationLoaded('dailyPayPayments')) {
            return null;
        }

        return $this->dailyPayPayments->isNotEmpty()
            ? PaymentStatus::Paid
            : PaymentStatus::Unpaid;
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** @return BelongsTo<Technician, $this> */
    public function paidByTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'paid_by_technician_id');
    }

    /** The shelf it was taken from, when source is from_storage. */
    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /** The shelf the unused remainder went back to. */
    /** @return BelongsTo<StorageLocation, $this> */
    public function returnedToStorageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'returned_to_storage_location_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'part_ticket_issue')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
