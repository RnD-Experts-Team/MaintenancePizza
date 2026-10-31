<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking on one or more issues -- a "schedule" in the contract: `kind`
 * work (do the job) or inspection (look and quote), for one technician, on a
 * date (and optional hour), with the trip that carries it. Moving it writes
 * an AssignmentDelay so the history of reschedules is kept. `ended_at` /
 * `ended_reason`: the issue was closed or cancelled before the day came, so
 * the booking no longer stands (kept for the record).
 */
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory, HasNotesAndAttachments;

    public const KINDS = ['work', 'inspection'];

    protected $fillable = [
        'kind',
        'technician_id',
        'assigned_date',
        'assigned_hour',
        'expected_completion_date',
        'trip_id',
        'issue_inspection_id',
        'mistaken',
        'ended_at',
        'ended_reason',
    ];

    /**
     * Saving this bumps its issues' updated_at, and through them the ticket's,
     * so "what changed" and "untouched" read straight off updated_at.
     */
    protected $touches = ['ticketIssues'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'expected_completion_date' => 'date',
            'mistaken' => 'boolean',
            'ended_at' => 'datetime',
        ];
    }

    /** @return HasMany<AssignmentDelay, $this> */
    public function delays(): HasMany
    {
        return $this->hasMany(AssignmentDelay::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'assignment_ticket_issue')->withTimestamps();
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

    /** @return BelongsTo<IssueInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(IssueInspection::class, 'issue_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
