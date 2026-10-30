<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use App\Models\Concerns\HasUniqueName;
use Database\Factories\TechnicianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A technician: a person OR a company we send to fix things. Names are unique
 * ignoring capitals and repeated spaces (HasUniqueName).
 *
 * There is no typed overall rating any more: how good a technician is, is the
 * average of their per-issue ratings (averageRating()). "Call first" is per
 * issue too, on the ability rows.
 *
 * `user_id` is always null today (technicians have no logins yet); when they
 * do, the account attaches here and everything keyed on the technician stays.
 */
class Technician extends Model
{
    /** @use HasFactory<TechnicianFactory> */
    use HasFactory, HasNotesAndAttachments, HasUniqueName, SoftDeletes;

    /** `user_id` is deliberately not fillable: read-only until logins exist. */
    protected $fillable = [
        'name',
        'phone',
        'contact_person',
        'category_id',
        'location',
        'coverage_notes',
        'hourly_rate',
        'emergency_available',
        'emergency_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'emergency_available' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The ticket issues this technician is assigned to.
     *
     * @return BelongsToMany<TicketIssue, $this>
     */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'technician_ticket_issue')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The catalog issues this technician covers: rating (1-5 or null), notes
     * and the per-issue "call first" pin. These ARE the covered issues.
     *
     * @return HasMany<TechnicianIssueAbility, $this>
     */
    public function abilities(): HasMany
    {
        return $this->hasMany(TechnicianIssueAbility::class);
    }

    /**
     * The stores this technician can cover.
     *
     * @return BelongsToMany<Store, $this>
     */
    public function coverageStores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_technician')->withTimestamps()->orderBy('store_number');
    }

    /** @return HasMany<Trip, $this> */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /** @return HasMany<TripSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TripSession::class);
    }

    /** @return HasMany<TechnicianPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(TechnicianPurchase::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * The average of the non-null per-issue ratings, to one decimal, as a
     * string; null when no issue is rated. Uses the loaded abilities when
     * they are loaded, so a list presenter does not query per row.
     */
    public function averageRating(): ?string
    {
        $ratings = $this->relationLoaded('abilities')
            ? $this->abilities->pluck('rating')->filter(fn ($r) => $r !== null)
            : $this->abilities()->whereNotNull('rating')->pluck('rating');

        if ($ratings->isEmpty()) {
            return null;
        }

        return number_format(round($ratings->avg(), 1), 1, '.', '');
    }

    /** `$technician->average_rating`, for presenters and serialisation. */
    public function getAverageRatingAttribute(): ?string
    {
        return $this->averageRating();
    }
}
