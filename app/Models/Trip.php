<?php

namespace App\Models;

use App\Enums\TripStatus;
use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One outing of one technician, which can cover several tickets at several
 * stores. The office creates it (scheduling a visit creates or extends the
 * technician's open trip) and sends him its link; he clocks in and out on it.
 *
 * Only the sha256 of the link's token is stored (`token_hash`), so the link
 * cannot be read back out of the database -- see TripService.
 */
class Trip extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'technician_id',
        'status',
        'planned_date',
        'token_hash',
        'link_idle_hours',
        'last_used_at',
        'closed_at',
        'instructions',
        'mistaken',
        'created_by',
    ];

    /** Never serialise the hash, even by accident. */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'planned_date' => 'date',
            'link_idle_hours' => 'integer',
            'last_used_at' => 'datetime',
            'closed_at' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /** @return HasMany<TripSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TripSession::class)->orderBy('clock_in');
    }

    /** @return HasMany<TripStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasManyThrough<TripStopIssue, TripStop, $this> */
    public function stopIssues(): HasManyThrough
    {
        return $this->hasManyThrough(TripStopIssue::class, TripStop::class);
    }

    /** @return HasMany<TechnicianPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(TechnicianPurchase::class);
    }

    /** @return HasMany<TripAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(TripAudit::class)->latest('id');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === TripStatus::Open;
    }
}
