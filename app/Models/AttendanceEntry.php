<?php

namespace App\Models;

use App\Enums\AttendanceEventKind;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\AttendanceEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceEntry extends Model
{
    /** @use HasFactory<AttendanceEntryFactory> */
    use HasFactory, HasNotesAndAttachments;

    /**
     * A pair longer than this is treated as a forgotten clock-out: the value is
     * clamped and flagged rather than believed.
     */
    private const MAX_PAIR_MINUTES = 1440;

    /** The buckets a session's spans fall into. `work` is the clock window. */
    private const BUCKETS = ['work', 'travel', 'break', 'parts_run'];

    /**
     * start_clock and end_clock are a CACHE of the clock_in / clock_out events,
     * rewritten by WorkflowRecordService inside the same transaction as every
     * event write. They are kept as columns because DailyPayEntryService runs
     * MIN/MAX/BETWEEN over them in raw SQL and overlaps() filters on both --
     * deriving them would make every pay run pay for a correlated subquery.
     *
     * Never set them by hand. Set the events; the cache follows.
     */
    protected $fillable = [
        'technician_id',
        'start_clock',
        'end_clock',
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
            'mistaken' => 'boolean',
        ];
    }

    /**
     * Duration of each bucket, in whole MINUTES, plus any warnings about the
     * data it was derived from.
     *
     * Walks the session's live events in time order, pairing each opening event
     * with the next closing one of the same bucket. So a session can now hold
     * as many breaks, travels and parts runs as actually happened -- which is
     * the thing the four fixed column-pairs made impossible.
     *
     * Nothing upstream constrains the order events are recorded in, and nothing
     * should: a coordinator writing up a visit from a phone call enters them as
     * they are remembered. So this must never throw and must never return a
     * negative -- payroll reads it. Anything unusable contributes zero and
     * raises a warning instead.
     *
     * `work` is NET: the break, parts-run and travel spans are merged and
     * subtracted, but only the portion of each that actually falls inside the
     * clock window. Techs record runs both inside and outside their shift, and
     * intersecting is correct under either convention. Merging before
     * subtracting stops an overlapping break and parts run being deducted
     * twice. The other three buckets are reported at their full recorded
     * length -- they are their own line items.
     *
     * AN OPEN SESSION IS NOT AN ERROR. A clock-in with no clock-out yet is the
     * normal state of somebody currently working, and it warns about nothing.
     * The dangling-half warning fires only once the session has been closed,
     * where a half-recorded break really is something somebody forgot.
     *
     * @return array{work: int, travel: int, break: int, parts_run: int, warnings: list<string>}
     */
    public function durations(): array
    {
        $events = $this->liveEvents();
        $closed = $events->contains(fn (AttendanceEvent $e) => $e->kind === AttendanceEventKind::ClockOut);

        $warnings = [];
        $spans = [];

        foreach (self::BUCKETS as $bucket) {
            [$spans[$bucket], $bucketWarnings] = $this->spansFor($events, $bucket, $closed);
            $warnings = array_merge($warnings, $bucketWarnings);
        }

        $minutes = [];

        foreach (self::BUCKETS as $bucket) {
            $minutes[$bucket] = $this->mergedMinutes($spans[$bucket]);
        }

        $work = $minutes['work'];

        // The clock window, as one interval. Several clock-in/clock-out pairs
        // in one session would be two sessions, so in practice this is one --
        // but merging keeps the maths total if it ever is not.
        $window = $spans['work'] === [] ? null : [
            min(array_column($spans['work'], 0)),
            max(array_column($spans['work'], 1)),
        ];

        if ($window !== null) {
            $deductions = [];

            foreach (['break', 'parts_run', 'travel'] as $bucket) {
                foreach ($spans[$bucket] as $span) {
                    $clipped = $this->intersect($span, $window);

                    if ($clipped !== null) {
                        $deductions[] = $clipped;
                    }
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
     * Every span of one bucket, paired off in time order.
     *
     * An opening event is held until the matching closing one arrives. A second
     * opening while one is already held means the first was never closed -- so
     * it is reported and dropped rather than silently swallowing the gap.
     *
     * @param  \Illuminate\Support\Collection<int, AttendanceEvent>  $events
     * @return array{0: list<array{0: int, 1: int}>, 1: list<string>}
     */
    private function spansFor($events, string $bucket, bool $sessionClosed): array
    {
        /** @var list<AttendanceEvent> $own */
        $own = $events->filter(fn (AttendanceEvent $e) => $e->kind->bucket() === $bucket)
            ->values()
            ->all();

        $spans = [];
        $warnings = [];
        $openAt = null;
        $count = count($own);

        for ($i = 0; $i < $count; $i++) {
            $event = $own[$i];

            if ($event->kind->opens()) {
                if ($openAt !== null) {
                    $warnings[] = "incomplete_pair:{$bucket}";
                }

                $openAt = $event->at->getTimestamp();

                continue;
            }

            if ($openAt === null) {
                /*
                 * A closing half with nothing open yet.
                 *
                 * This is where an INVERTED pair shows up. Sorting by time is
                 * what makes out-of-order entry work, but it also means a pair
                 * typed the wrong way round -- clocked in 16:00, clocked out
                 * 08:00 -- arrives as close-then-open rather than as a negative
                 * span. Recognising the adjacent pair is the only way to tell
                 * that apart from a genuinely orphaned half, and telling them
                 * apart matters: one is a typo, the other is a forgotten press.
                 */
                if ($i + 1 < $count && $own[$i + 1]->kind->opens()) {
                    $warnings[] = "inverted_pair:{$bucket}";
                    $i++; // consume the open too; the pair counts as zero

                    continue;
                }

                $warnings[] = "incomplete_pair:{$bucket}";

                continue;
            }

            $to = $event->at->getTimestamp();

            if (intdiv($to - $openAt, 60) > self::MAX_PAIR_MINUTES) {
                // A forgotten close. Clamped rather than believed, and the
                // clamped span is what gets subtracted, so the reported figure
                // and the deduction stay consistent.
                $warnings[] = "implausible_pair:{$bucket}";
                $spans[] = [$openAt, $openAt + self::MAX_PAIR_MINUTES * 60];
                $openAt = null;

                continue;
            }

            $spans[] = [$openAt, $to];
            $openAt = null;
        }


        // Something still open at the end. Only a problem once the session is
        // closed -- while it is running, this is just somebody still driving.
        if ($openAt !== null && $sessionClosed) {
            $warnings[] = "incomplete_pair:{$bucket}";
        }

        return [$spans, array_values(array_unique($warnings))];
    }

    /**
     * The events that count, oldest first.
     *
     * Sorted by `at`, NOT by id: reality does not arrive in insertion order. A
     * coordinator catching up on a visit may enter the clock-out before
     * remembering the break, and the session still has to read as a story.
     *
     * @return \Illuminate\Support\Collection<int, AttendanceEvent>
     */
    public function liveEvents()
    {
        $events = $this->relationLoaded('events')
            ? $this->events
            : $this->events()->get();

        return $events->reject(fn (AttendanceEvent $e) => $e->mistaken)
            ->sortBy(fn (AttendanceEvent $e) => $e->at->getTimestamp())
            ->values();
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
     * Now load-bearing in a second place: a session with two breaks produces
     * two spans in one bucket, and this is what turns them into one figure
     * without double-counting an overlap.
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

    /**
     * Everything that happened during this session.
     *
     * NAMED `events` TO MATCH ITS ROUTE PARAMETER `{event}`. Laravel resolves a
     * scoped binding's relation as Str::plural(Str::camel($param)), and getting
     * that wrong is a 500 on every nested write -- as the storage slots feature
     * demonstrated.
     *
     * @return HasMany<AttendanceEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class)->orderBy('at')->orderBy('id');
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
