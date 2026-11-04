<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One clock-in → clock-out span of a trip. A trip can have many: clocking
 * out for the night and back in next morning is two sessions of one trip.
 *
 * `open_marker` is TRUE on the one live session and NULL otherwise, under a
 * unique (technician_id, open_marker) index: the database itself guarantees a
 * technician is clocked in at most once. The saving hook below keeps it in
 * step with clock_out and mistaken, so callers never set it themselves.
 *
 * Durations are worked out by TripService::durations(); a session is paid at
 * most once (unique trip_session_id on payment_line_trip_session).
 */
class TripSession extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'trip_id',
        'technician_id',
        'clock_in',
        'clock_out',
        'open_marker',
        'using_car',
        'odometer_in',
        'odometer_out',
        'source',
        'mistaken',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'open_marker' => 'boolean',
            'using_car' => 'boolean',
            'odometer_in' => 'integer',
            'odometer_out' => 'integer',
            'mistaken' => 'boolean',
        ];
    }

    /**
     * Keep the "clocked in once" marker in step with clock_out, so no caller
     * can forget it: an open, non-mistaken session carries TRUE, every other
     * one NULL (NULLs never collide in a unique index).
     */
    protected static function booted(): void
    {
        static::saving(function (TripSession $session) {
            $session->open_marker = ($session->clock_out === null && ! $session->mistaken) ? true : null;
        });
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /** @return HasMany<TripEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TripEvent::class)->orderBy('at')->orderBy('id');
    }

    /**
     * The payment line that paid this session (at most one, by a unique index).
     *
     * @return BelongsToMany<PaymentLine, $this>
     */
    public function paymentLines(): BelongsToMany
    {
        return $this->belongsToMany(PaymentLine::class, 'payment_line_trip_session')
            ->withPivot('paid_minutes')
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->clock_out === null;
    }

    /**
     * Closed, not mistaken, and claimed by no payment line.
     *
     * @param  Builder<TripSession>  $query
     */
    public function scopeUnpaid(Builder $query): void
    {
        // Paid means claimed by a payment that still stands: a payment marked
        // as a mistake releases its claims, and must not keep the time paid
        // even if a claim row were ever left behind.
        $query->whereNotNull('clock_out')
            ->where('mistaken', false)
            ->whereDoesntHave('paymentLines', fn (Builder $line) => $line->whereHas('payment', fn (Builder $p) => $p->where('mistaken', false)));
    }
}
