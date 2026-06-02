<?php

namespace App\Models;

use App\Enums\IssueStatus;
use App\Enums\Priority;
use Database\Factories\TicketIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketIssue extends Model
{
    /** @use HasFactory<TicketIssueFactory> */
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'issue_id',
        'other_title',
        'priority',
        'description',
        'status',
        'parent_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => IssueStatus::class,
        ];
    }

    /**
     * The label to show for this issue: the catalog title or the free-text "other".
     */
    public function displayTitle(): string
    {
        return $this->issue?->title ?? (string) $this->other_title;
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class, 'parent_id');
    }

    /** @return HasMany<TicketIssue, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(TicketIssue::class, 'parent_id');
    }

    /** @return HasMany<IssueStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(IssueStatusChange::class);
    }

    /** @return BelongsToMany<Assignment, $this> */
    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(Assignment::class, 'assignment_ticket_issue')->withTimestamps();
    }

    /** @return BelongsToMany<Diagnosis, $this> */
    public function diagnoses(): BelongsToMany
    {
        return $this->belongsToMany(Diagnosis::class, 'diagnosis_ticket_issue')->withTimestamps();
    }

    /** @return BelongsToMany<AttendanceEntry, $this> */
    public function attendanceEntries(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceEntry::class, 'attendance_entry_ticket_issue')->withTimestamps();
    }

    /** @return BelongsToMany<PartUsage, $this> */
    public function partUsages(): BelongsToMany
    {
        return $this->belongsToMany(PartUsage::class, 'part_ticket_issue')->withTimestamps();
    }

    /** @return BelongsToMany<PayEntry, $this> */
    public function payEntries(): BelongsToMany
    {
        return $this->belongsToMany(PayEntry::class, 'pay_entry_ticket_issue')->withTimestamps();
    }

    /** @return BelongsToMany<Warranty, $this> */
    public function warranties(): BelongsToMany
    {
        return $this->belongsToMany(Warranty::class, 'warranty_ticket_issue')->withTimestamps();
    }

    /** @return BelongsToMany<Technician, $this> */
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'technician_ticket_issue')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
