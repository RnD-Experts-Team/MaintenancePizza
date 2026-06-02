<?php

namespace App\Models;

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
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'category_id'];

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
}
