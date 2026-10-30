<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One individual piece of a part with `track_serials` on: its serial number,
 * where it is (status in_stock | installed | returned | damaged_lost, with the
 * storage location or the ticket issue it went to), and its warranty.
 */
class PartUnit extends Model
{
    public const STATUSES = ['in_stock', 'installed', 'returned', 'damaged_lost'];

    protected $fillable = [
        'part_id',
        'serial_number',
        'status',
        'storage_location_id',
        'ticket_issue_id',
        'payment_line_id',
        'installed_at',
        'warranty_until',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'warranty_until' => 'date',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class)->withTrashed();
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }

    /** @return BelongsTo<PaymentLine, $this> */
    public function paymentLine(): BelongsTo
    {
        return $this->belongsTo(PaymentLine::class);
    }

    /**
     * Every stock movement line this unit travelled on.
     *
     * @return BelongsToMany<StockMovementLine, $this>
     */
    public function movementLines(): BelongsToMany
    {
        return $this->belongsToMany(StockMovementLine::class, 'stock_movement_line_part_unit')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
