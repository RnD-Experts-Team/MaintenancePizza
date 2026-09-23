<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One store's share of a payment: the hours worked there, the money spent
 * there, and the ticket issues it covers.
 *
 * INVARIANT: technician_id always equals payment.technician_id. It is
 * denormalised, and kept only so DailyPayEntryService::list()'s technician
 * filter and DailyPayLinesSheet keep working unchanged. Never set it to
 * anything else — createLine() copies it from the payment.
 */
class DailyPayLine extends Model
{
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'daily_pay_entry_id',
        'daily_pay_payment_id',
        'technician_id',
        'store_id',
        'other_store',
        'total_working_hours',
        'gas',
        'lump_sum',
        'hourly_payment_rate',
        'money_owed',
        'travel_time',
        'total_break_time',
        'parts_run_time',
        'line_total',
        'frozen_work_hours',
        'frozen_travel_hours',
        'frozen_break_hours',
        'frozen_parts_run_hours',
        'frozen_reimbursable_parts',
        'hours_overridden',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_working_hours' => 'decimal:2',
            'gas' => 'decimal:2',
            'lump_sum' => 'decimal:2',
            'hourly_payment_rate' => 'decimal:4',
            'money_owed' => 'decimal:2',
            'travel_time' => 'decimal:2',
            'total_break_time' => 'decimal:2',
            'parts_run_time' => 'decimal:2',
            'line_total' => 'decimal:2',
            'frozen_work_hours' => 'decimal:2',
            'frozen_travel_hours' => 'decimal:2',
            'frozen_break_hours' => 'decimal:2',
            'frozen_parts_run_hours' => 'decimal:2',
            'frozen_reimbursable_parts' => 'decimal:2',
            'hours_overridden' => 'boolean',
        ];
    }

    /** @return BelongsTo<DailyPayEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(DailyPayEntry::class, 'daily_pay_entry_id');
    }

    /** @return BelongsTo<DailyPayPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(DailyPayPayment::class, 'daily_pay_payment_id');
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
