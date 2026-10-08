<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\TechnicianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technician extends Model
{
    /** @use HasFactory<TechnicianFactory> */
    use HasFactory, HasNotesAndAttachments, SoftDeletes;

    /**
     * The overall rating (rating, rating_notes, call_first) is deliberately
     * not fillable: it changes only through TechnicianAbilityService, which
     * moves the single overall "call first" pin.
     */
    protected $fillable = ['name', 'phone', 'category_id', 'location', 'coverage_notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'call_first' => 'boolean',
            'rating_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<AttendanceEntry, $this> */
    public function attendanceEntries(): HasMany
    {
        return $this->hasMany(AttendanceEntry::class);
    }

    /** @return HasMany<PayEntry, $this> */
    public function payEntries(): HasMany
    {
        return $this->hasMany(PayEntry::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
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
     * How good this technician is at each catalog issue.
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

    /** @return BelongsTo<User, $this> */
    public function ratingEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rating_updated_by');
    }
}
