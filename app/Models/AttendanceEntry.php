<?php

namespace App\Models;

use Database\Factories\AttendanceEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AttendanceEntry extends Model
{
    /** @use HasFactory<AttendanceEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'technician_id',
        'start_clock',
        'end_clock',
        'start_break',
        'end_break',
        'start_parts_run',
        'end_parts_run',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_clock' => 'datetime',
            'end_clock' => 'datetime',
            'start_break' => 'datetime',
            'end_break' => 'datetime',
            'start_parts_run' => 'datetime',
            'end_parts_run' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'attendance_entry_ticket_issue')->withTimestamps();
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
