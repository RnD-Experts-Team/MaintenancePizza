<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\AttendanceEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttendanceEntry extends Model
{
    /** @use HasFactory<AttendanceEntryFactory> */
    use HasFactory, HasNotesAndAttachments;

    /**
     * A pair longer than this is treated as a forgotten clock-out: the value is
     * clamped and flagged rather than believed.
     */
    private const MAX_PAIR_MINUTES = 1440;

    /**
     * The four start/end pairs, keyed by the bucket they contribute to.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PAIRS = [
        'work' => ['start_clock', 'end_clock'],
        'break' => ['start_break', 'end_break'],
        'parts_run' => ['start_parts_run', 'end_parts_run'],
        'travel' => ['start_travel', 'end_travel'],
    ];

    protected $fillable = [
        'technician_id',
        'start_clock',
        'end_clock',
        'start_break',
        'end_break',
        'start_parts_run',
        'end_parts_run',
        'start_travel',
        'end_travel',
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
            'start_travel' => 'datetime',
            'end_travel' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /**
     * Duration of each bucket, in whole MINUTES, plus any warnings about the
     * data it was derived from.
     *
     * The clock columns are deliberately unconstrained (see the table
     * migration: "the dispatcher may set any subset, repeatedly"), and nothing
     * validates that an end follows its start. So this must never throw and
     * must never return a negative — payroll reads it. Bad pairs contribute
     * zero and raise a warning instead.
     *
     * `work` is NET: the break, parts-run and travel intervals are merged and
     * subtracted, but only the portion of each that actually falls inside the
     * clock window. Techs record runs both inside and outside their shift, and
     * intersecting is correct under either convention. Merging before
     * subtracting stops an overlapping break and parts run being deducted
     * twice. The other three buckets are reported at their full recorded
     * length — they are their own line items.
     *
     * @return array{work: int, travel: int, break: int, parts_run: int, warnings: list<string>}
     */
    public function durations(): array
    {
        $warnings = [];
        $minutes = [];
        $intervals = [];

        foreach (self::PAIRS as $bucket => [$startField, $endField]) {
            [$minutes[$bucket], $intervals[$bucket], $warning] = $this->pair($bucket, $startField, $endField);

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $work = $minutes['work'];

        if ($intervals['work'] !== null) {
            $deductions = [];

            foreach (['break', 'parts_run', 'travel'] as $bucket) {
                $clipped = $this->intersect($intervals[$bucket], $intervals['work']);

                if ($clipped !== null) {
                    $deductions[] = $clipped;
                }
            }

            $work = max(0, $work - $this->mergedMinutes($deductions));
        }

        return [
            'work' => $work,
            'travel' => $minutes['travel'],
            'break' => $minutes['break'],
            'parts_run' => $minutes['parts_run'],
            'warnings' => $warnings,
        ];
    }

    /**
     * One start/end pair as [minutes, interval, warning]. The interval is a
     * [startTimestamp, endTimestamp] tuple, or null when there is nothing
     * usable to intersect against.
     *
     * @return array{0: int, 1: ?array{0: int, 1: int}, 2: ?string}
     */
    private function pair(string $bucket, string $startField, string $endField): array
    {
        $start = $this->{$startField};
        $end = $this->{$endField};

        if ($start === null && $end === null) {
            // Simply not recorded. Not an error, so no warning.
            return [0, null, null];
        }

        if ($start === null || $end === null) {
            return [0, null, "incomplete_pair:{$bucket}"];
        }

        $from = $start->getTimestamp();
        $to = $end->getTimestamp();

        if ($to < $from) {
            return [0, null, "inverted_pair:{$bucket}"];
        }

        $span = intdiv($to - $from, 60);

        if ($span > self::MAX_PAIR_MINUTES) {
            // Clamp the interval too, so the subtraction below stays consistent
            // with the reported figure.
            return [self::MAX_PAIR_MINUTES, [$from, $from + self::MAX_PAIR_MINUTES * 60], "implausible_pair:{$bucket}"];
        }

        return [$span, [$from, $to], null];
    }

    /**
     * The overlap of two intervals, or null when they do not overlap.
     *
     * @param  ?array{0: int, 1: int}  $interval
     * @param  array{0: int, 1: int}  $window
     * @return ?array{0: int, 1: int}
     */
    private function intersect(?array $interval, array $window): ?array
    {
        if ($interval === null) {
            return null;
        }

        $from = max($interval[0], $window[0]);
        $to = min($interval[1], $window[1]);

        return $to > $from ? [$from, $to] : null;
    }

    /**
     * Total minutes covered by these intervals, counting overlaps once.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function mergedMinutes(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }

        usort($intervals, fn(array $a, array $b) => $a[0] <=> $b[0]);

        $seconds = 0;
        [$from, $to] = $intervals[0];

        foreach (array_slice($intervals, 1) as [$nextFrom, $nextTo]) {
            if ($nextFrom > $to) {
                $seconds += $to - $from;
                [$from, $to] = [$nextFrom, $nextTo];

                continue;
            }

            $to = max($to, $nextTo);
        }

        return intdiv($seconds + ($to - $from), 60);
    }

    /**
     * The payments that have counted these hours, with the minutes each took.
     *
     * @return BelongsToMany<DailyPayPayment, $this>
     */
    public function dailyPayPayments(): BelongsToMany
    {
        return $this->belongsToMany(DailyPayPayment::class, 'daily_pay_payment_attendance_entry')
            ->withPivot(['daily_pay_line_id', 'work_minutes', 'travel_minutes', 'break_minutes', 'parts_run_minutes'])
            ->withTimestamps();
    }

    /**
     * Whether these hours have been settled through a pay sheet. Being on a
     * sheet IS being paid — there is no separate "money sent" step.
     *
     * Derived, never stored, so it cannot drift from the sheets it describes.
     * Returns null when the claims are not loaded, the same convention the
     * presenters use for relations.
     */
    public function paymentStatus(): ?PaymentStatus
    {
        if ($this->mistaken) {
            return PaymentStatus::NotPayable;
        }

        if (! $this->relationLoaded('dailyPayPayments')) {
            return null;
        }

        return $this->dailyPayPayments->isNotEmpty()
            ? PaymentStatus::Paid
            : PaymentStatus::Unpaid;
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
