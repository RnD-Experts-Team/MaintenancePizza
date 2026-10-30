<?php

namespace App\Models;

use App\Enums\InspectionStatus;
use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One look at the problem by a technician: when it is booked, what he found,
 * the repair he recommends, the parts it needs and his quote. An issue can
 * have several (a second opinion).
 */
class IssueInspection extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'ticket_issue_id',
        'technician_id',
        'status',
        'scheduled_date',
        'scheduled_time',
        'done_at',
        'findings',
        'recommended_repair',
        'parts_needed',
        'estimated_cost',
        'mistaken',
        'created_by',
    ];

    protected $touches = ['ticketIssue'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InspectionStatus::class,
            'scheduled_date' => 'date',
            'done_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /** @return HasMany<IssueApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(IssueApproval::class);
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
}
