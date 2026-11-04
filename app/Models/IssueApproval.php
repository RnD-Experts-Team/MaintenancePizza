<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decision on a quote. `level` is worked out from the amount and the
 * thresholds in maintenance_settings.approval_levels at the time it was
 * asked. Who approved is a NAME today; `approver_user_id` is there so real
 * approver roles can be enforced later without changing the table.
 */
class IssueApproval extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'ticket_issue_id',
        'issue_inspection_id',
        'amount',
        'level',
        'decision',
        'approver_name',
        'approver_user_id',
        'decided_at',
        'note',
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
            'amount' => 'decimal:2',
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }

    /** @return BelongsTo<IssueInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(IssueInspection::class, 'issue_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
