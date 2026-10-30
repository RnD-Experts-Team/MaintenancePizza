<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One store on a trip (or an `other_store` written in words): when he got
 * there and left, the odometer on arrival, and the ticket issues he is there
 * for (`trip_stop_issues`). Status: planned | arrived | done | not_done.
 */
class TripStop extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'trip_id',
        'store_id',
        'other_store',
        'status',
        'arrived_at',
        'left_at',
        'arrival_odometer',
        'not_done_reason',
        'sort_order',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'left_at' => 'datetime',
            'arrival_odometer' => 'integer',
            'sort_order' => 'integer',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<TripStopIssue, $this> */
    public function stopIssues(): HasMany
    {
        return $this->hasMany(TripStopIssue::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'trip_stop_issues')
            ->withPivot(['id', 'purpose', 'task_status', 'done_at', 'not_done_reason'])
            ->withTimestamps();
    }

    /** @return HasMany<TechnicianPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(TechnicianPurchase::class);
    }
}
