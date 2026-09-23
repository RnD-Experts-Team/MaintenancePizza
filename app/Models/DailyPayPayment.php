<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment to one payee on one date, covering however many stores.
 *
 * The payee is a Technician row — a "company" is just a technician named after
 * the company, so there is no separate payee entity.
 *
 * `money_owed` is NOT the total. It is an optional extra we also owe them;
 * `total_amount` is the payable figure.
 */
class DailyPayPayment extends Model
{
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'daily_pay_entry_id',
        'technician_id',
        'hourly_payment_rate',
        'lump_sum',
        'gas',
        'money_owed',
        'frozen_work_hours',
        'frozen_travel_hours',
        'frozen_break_hours',
        'frozen_parts_run_hours',
        'frozen_reimbursable_parts',
        'aggregated_at',
        'aggregated_by',
        'aggregation_warnings',
        'lines_total',
        'total_amount',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_payment_rate' => 'decimal:4',
            'lump_sum' => 'decimal:2',
            'gas' => 'decimal:2',
            'money_owed' => 'decimal:2',
            'frozen_work_hours' => 'decimal:2',
            'frozen_travel_hours' => 'decimal:2',
            'frozen_break_hours' => 'decimal:2',
            'frozen_parts_run_hours' => 'decimal:2',
            'frozen_reimbursable_parts' => 'decimal:2',
            'aggregated_at' => 'datetime',
            'aggregation_warnings' => 'array',
            'lines_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
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

    /** @return HasMany<DailyPayLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DailyPayLine::class, 'daily_pay_payment_id');
    }

    /**
     * The attendance entries this payment has counted, with the minutes it took
     * from each. The pivot's unique index is what guarantees "once per payment".
     *
     * @return BelongsToMany<AttendanceEntry, $this>
     */
    public function attendanceEntries(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceEntry::class, 'daily_pay_payment_attendance_entry')
            ->withPivot(['daily_pay_line_id', 'work_minutes', 'travel_minutes', 'break_minutes', 'parts_run_minutes'])
            ->withTimestamps();
    }

    /**
     * The reimbursable part usages this payment has counted, with the amount
     * allowed for each.
     *
     * @return BelongsToMany<PartUsage, $this>
     */
    public function partUsages(): BelongsToMany
    {
        return $this->belongsToMany(PartUsage::class, 'daily_pay_payment_part_usage')
            ->withPivot(['daily_pay_line_id', 'store_id', 'amount'])
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whoever last ran the gather. */
    /** @return BelongsTo<User, $this> */
    public function aggregator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aggregated_by');
    }
}
