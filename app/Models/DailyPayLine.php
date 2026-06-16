<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DailyPayLine extends Model
{
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'daily_pay_entry_id',
        'technician_id',
        'store_id',
        'total_working_hours',
        'gas',
        'invoices',
        'hourly_payment_rate',
        'money_owed',
        'travel_time',
        'total_break_time',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_working_hours' => 'decimal:2',
            'gas' => 'decimal:2',
            'invoices' => 'decimal:2',
            'hourly_payment_rate' => 'decimal:4',
            'money_owed' => 'decimal:2',
            'travel_time' => 'decimal:2',
            'total_break_time' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<DailyPayEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(DailyPayEntry::class, 'daily_pay_entry_id');
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'daily_pay_line_ticket_issue')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
