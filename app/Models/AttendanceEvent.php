<?php

namespace App\Models;

use App\Enums\AttendanceEventKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened on the clock: clocked in, set off, arrived, went on
 * break, came back, clocked out.
 *
 * Each of these is its own record. That is the point -- adding "he set off at
 * 08:30" to a session recorded an hour ago is now an insert, where before it
 * meant flagging the whole entry wrong and typing the lot again.
 *
 * Append-only in spirit: `mistaken` strikes an event without removing it, the
 * same flag the rest of the system uses. `at` may be corrected while nobody has
 * been paid against the session -- see WorkflowRecordService::updateAttendanceEvent(),
 * which refuses once a pay sheet has claimed it. You can fix what nobody has
 * been paid for; you cannot quietly rewrite what somebody was paid on.
 */
class AttendanceEvent extends Model
{
    protected $fillable = ['attendance_entry_id', 'kind', 'at', 'mistaken'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttendanceEventKind::class,
            'at' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<AttendanceEntry, $this> */
    public function attendanceEntry(): BelongsTo
    {
        return $this->belongsTo(AttendanceEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
