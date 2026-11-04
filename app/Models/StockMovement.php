<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One batch of stock moving. The ledger is append-only and is the source of
 * truth for what is on hand:
 *
 *     balance(part, location) = SUM(quantity * direction) over ALL lines
 *
 * with no filtering whatsoever. A mistake is corrected by writing an
 * equal-and-opposite `undone` movement, never by editing or deleting.
 * `mistaken` is an audit flag for display and has NO effect on that sum:
 * excluding flagged rows AND writing an undo would correct the same error
 * twice.
 *
 * What caused it, when something did: the payment line that bought the parts
 * (`bought`), the ticket issue they were used on (`used`), or the stock count
 * that corrected them (`stock_count`).
 *
 * `paid_by` / `paid_by_technician_id` / `part_usage_id` are v1 columns kept
 * for the converted history; nothing writes them any more.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'moved_at',
        'type',
        'storage_location_id',
        'paid_by',
        'paid_by_technician_id',
        'technician_id',
        'body',
        'payment_line_id',
        'ticket_issue_id',
        'stock_count_id',
        'reverses_stock_movement_id',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
            'type' => StockMovementType::class,
            'mistaken' => 'boolean',
        ];
    }

    /** @return HasMany<StockMovementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovementLine::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /** @return BelongsTo<Technician, $this> */
    public function paidByTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'paid_by_technician_id');
    }

    /** The technician who did it (took, moved, returned the parts). Optional. */
    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /** @return BelongsTo<PaymentLine, $this> */
    public function paymentLine(): BelongsTo
    {
        return $this->belongsTo(PaymentLine::class);
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /**
     * The movement this one undoes, when it is an undo.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reverses_stock_movement_id');
    }

    /**
     * The undo that reversed this movement, if one was written.
     *
     * @return HasOne<StockMovement, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'reverses_stock_movement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
