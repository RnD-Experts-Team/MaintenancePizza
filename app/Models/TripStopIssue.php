<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ticket issue a technician is at a stop for, and whether he got it done.
 * `purpose` says what he was sent to do: `work` (fix it) or `inspection` (look
 * and quote). task_status: pending | done | not_done (with a reason).
 */
class TripStopIssue extends Model
{
    protected $fillable = [
        'trip_stop_id',
        'ticket_issue_id',
        'purpose',
        'task_status',
        'done_at',
        'not_done_reason',
    ];

    /** Doing the job is a change to the issue (and so to its ticket). */
    protected $touches = ['ticketIssue'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'done_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TripStop, $this> */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(TripStop::class, 'trip_stop_id');
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }
}
