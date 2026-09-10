<?php

namespace App\Services;

use App\Enums\PartUsagePayer;
use App\Models\AttendanceEntry;
use App\Models\DailyPayEntry;
use App\Models\DailyPayLine;
use App\Models\DailyPayPayment;
use App\Models\PartUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Gathers what a payment is owed from the records that already exist — the
 * attendance clocked against its issues, and the parts somebody else paid for —
 * and freezes those figures onto the payment and its lines.
 *
 * Frozen rather than live: a pay sheet that has been signed off must not change
 * because someone corrected a ticket a month later. POST .../recalculate
 * re-runs the gather when you actually want the newer numbers.
 *
 * Always called from inside the caller's transaction; it never opens its own.
 */
class DailyPayAggregationService
{
    /** Break time is tracked but not paid. Work, travel and parts runs are. */
    private const PAID_BUCKETS = ['work', 'travel', 'parts_run'];

    public function recalculate(DailyPayEntry $entry): void
    {
        $entry->load('payments.lines.ticketIssues');

        foreach ($entry->payments as $payment) {
            $this->freeze($payment);
        }
    }

    /**
     * Gather, attribute, write the frozen columns, then total up.
     */
    public function freeze(DailyPayPayment $payment): void
    {
        $payment->loadMissing(['entry', 'lines.ticketIssues']);

        $warnings = [];
        $issueToLine = $this->issueToLineMap($payment);

        $this->gatherAttendance($payment, $issueToLine, $warnings);
        $this->gatherParts($payment, $issueToLine, $warnings);

        $payment->aggregated_at = now();
        $payment->aggregated_by = Auth::id();
        $payment->aggregation_warnings = array_values($warnings);
        $payment->save();

        $this->computeTotals($payment);
    }

    // ------------------------------------------------------------- Attendance

    /**
     * @param  array<int, int>  $issueToLine
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function gatherAttendance(DailyPayPayment $payment, array $issueToLine, array &$warnings): void
    {
        $issueIds = array_keys($issueToLine);

        if ($issueIds === []) {
            $this->clearAttendanceClaims($payment);
            $this->writeFrozenHours($payment, []);

            return;
        }

        /** @var Collection<int, AttendanceEntry> $entries */
        $entries = AttendanceEntry::query()
            ->where('technician_id', $payment->technician_id)
            ->where('mistaken', false)
            // Scoped by the work, not by the date: a night shift crosses
            // midnight and start_clock is nullable, so filtering on the date
            // would quietly drop real hours. Entries recorded on another day
            // are warned about below instead.
            ->whereHas('ticketIssues', fn (Builder $q) => $q->whereIn('ticket_issues.id', $issueIds))
            ->with('ticketIssues:id')
            ->get();

        $claims = [];
        $perLine = [];
        $totals = ['work' => 0, 'travel' => 0, 'break' => 0, 'parts_run' => 0];

        foreach ($entries as $entry) {
            $durations = $entry->durations();

            foreach ($durations['warnings'] as $warning) {
                $warnings[] = ['code' => $warning, 'context' => ['attendance_entry_id' => $entry->id]];
            }

            $payDate = $payment->entry?->date?->toDateString();
            $entryDate = $entry->start_clock?->toDateString() ?? $entry->start_travel?->toDateString();

            if ($payDate && $entryDate && $payDate !== $entryDate) {
                $warnings[] = [
                    'code' => 'entry_outside_date',
                    'context' => ['attendance_entry_id' => $entry->id, 'entry_date' => $entryDate, 'pay_date' => $payDate],
                ];
            }

            $lineId = $this->attributeToLine($entry->ticketIssues->pluck('id')->all(), $issueToLine);

            $claims[] = [
                'daily_pay_payment_id' => $payment->id,
                'attendance_entry_id' => $entry->id,
                'daily_pay_line_id' => $lineId,
                'work_minutes' => $durations['work'],
                'travel_minutes' => $durations['travel'],
                'break_minutes' => $durations['break'],
                'parts_run_minutes' => $durations['parts_run'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ($totals as $bucket => $_) {
                $totals[$bucket] += $durations[$bucket];

                if ($lineId !== null) {
                    $perLine[$lineId][$bucket] = ($perLine[$lineId][$bucket] ?? 0) + $durations[$bucket];
                }
            }
        }

        foreach ($this->overlaps($entries) as $pair) {
            $warnings[] = ['code' => 'overlapping_entries', 'context' => ['attendance_entry_ids' => $pair]];
        }

        $this->clearAttendanceClaims($payment);

        if ($claims !== []) {
            // The unique (payment, entry) index makes this once-per-payment even
            // though an entry may be linked to several of the payment's issues.
            DB::table('daily_pay_payment_attendance_entry')->upsert(
                $claims,
                ['daily_pay_payment_id', 'attendance_entry_id'],
                ['daily_pay_line_id', 'work_minutes', 'travel_minutes', 'break_minutes', 'parts_run_minutes', 'updated_at']
            );
        }

        $this->writeFrozenHours($payment, $totals);
        $this->writeLineHours($payment, $perLine);
    }

    /**
     * Two entries for the same person whose clock windows overlap almost
     * certainly means a double-clock. Report it; never silently de-duplicate,
     * because that would quietly cut someone's pay with no visible cause.
     *
     * @param  Collection<int, AttendanceEntry>  $entries
     * @return array<int, array<int, int>>
     */
    private function overlaps(Collection $entries): array
    {
        $windows = $entries
            ->filter(fn (AttendanceEntry $e) => $e->start_clock && $e->end_clock && $e->end_clock >= $e->start_clock)
            ->map(fn (AttendanceEntry $e) => [
                'id' => $e->id,
                'from' => $e->start_clock->getTimestamp(),
                'to' => $e->end_clock->getTimestamp(),
            ])
            ->sortBy('from')
            ->values()
            ->all();

        $found = [];

        for ($i = 0; $i < count($windows) - 1; $i++) {
            for ($j = $i + 1; $j < count($windows); $j++) {
                if ($windows[$j]['from'] >= $windows[$i]['to']) {
                    break;
                }

                $found[] = [$windows[$i]['id'], $windows[$j]['id']];
            }
        }

        return $found;
    }

    // ------------------------------------------------------------------ Parts

    /**
     * @param  array<int, int>  $issueToLine
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function gatherParts(DailyPayPayment $payment, array $issueToLine, array &$warnings): void
    {
        $issueIds = array_keys($issueToLine);

        if ($issueIds === []) {
            $this->clearPartClaims($payment);
            $payment->frozen_reimbursable_parts = '0.00';
            $this->writeLineParts($payment, []);

            return;
        }

        /** @var Collection<int, PartUsage> $usages */
        $usages = PartUsage::query()
            ->where('mistaken', false)
            // Reimbursable means someone other than us was out of pocket.
            ->where('paid_by', '!=', PartUsagePayer::Us->value)
            ->where('paid_by_technician_id', $payment->technician_id)
            ->whereHas('ticketIssues', fn (Builder $q) => $q->whereIn('ticket_issues.id', $issueIds))
            // One usage attaches to many issues; keying on the usage's own id is
            // what stops one receipt being paid once per issue it touched.
            ->with('ticketIssues:id,ticket_id')
            ->get();

        $alreadyClaimed = $this->claimedElsewhere($payment, $usages->pluck('id')->all());

        $claims = [];
        $perLine = [];
        $total = '0.00';

        foreach ($usages as $usage) {
            if (isset($alreadyClaimed['same_entry'][$usage->id])) {
                $warnings[] = [
                    'code' => 'already_claimed_same_date',
                    'context' => ['part_usage_id' => $usage->id, 'daily_pay_payment_id' => $alreadyClaimed['same_entry'][$usage->id]],
                ];

                continue;
            }

            if (isset($alreadyClaimed['other_entry'][$usage->id])) {
                $warnings[] = [
                    'code' => 'claimed_on_other_entry',
                    'context' => ['part_usage_id' => $usage->id, 'daily_pay_entry_id' => $alreadyClaimed['other_entry'][$usage->id]],
                ];
            }

            $covered = $usage->ticketIssues->pluck('id')->all();
            $lineId = $this->attributeToLine($covered, $issueToLine, $spanned);

            if ($spanned) {
                $warnings[] = ['code' => 'part_usage_spans_stores', 'context' => ['part_usage_id' => $usage->id]];
            }

            // Reimburse what they are actually out of pocket, not the gross:
            // paying the full cost on a part partly handed back would overpay.
            $amount = $usage->netCost();

            $claims[] = [
                'daily_pay_payment_id' => $payment->id,
                'part_usage_id' => $usage->id,
                'daily_pay_line_id' => $lineId,
                'store_id' => $lineId ? $payment->lines->firstWhere('id', $lineId)?->store_id : null,
                'amount' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $total = bcadd($total, $amount, 2);

            if ($lineId !== null) {
                $perLine[$lineId] = bcadd($perLine[$lineId] ?? '0.00', $amount, 2);
            }
        }

        $this->clearPartClaims($payment);

        if ($claims !== []) {
            DB::table('daily_pay_payment_part_usage')->upsert(
                $claims,
                ['daily_pay_payment_id', 'part_usage_id'],
                ['daily_pay_line_id', 'store_id', 'amount', 'updated_at']
            );
        }

        $payment->frozen_reimbursable_parts = $total;
        $this->writeLineParts($payment, $perLine);
    }

    /**
     * Which of these usages some OTHER payment has already claimed, split by
     * whether that payment is on the same pay sheet or a different one.
     *
     * @param  array<int, int>  $usageIds
     * @return array{same_entry: array<int, int>, other_entry: array<int, int>}
     */
    private function claimedElsewhere(DailyPayPayment $payment, array $usageIds): array
    {
        if ($usageIds === []) {
            return ['same_entry' => [], 'other_entry' => []];
        }

        $rows = DB::table('daily_pay_payment_part_usage as claim')
            ->join('daily_pay_payments as p', 'p.id', '=', 'claim.daily_pay_payment_id')
            ->whereIn('claim.part_usage_id', $usageIds)
            ->where('claim.daily_pay_payment_id', '!=', $payment->id)
            ->select('claim.part_usage_id', 'claim.daily_pay_payment_id', 'p.daily_pay_entry_id')
            ->get();

        $result = ['same_entry' => [], 'other_entry' => []];

        foreach ($rows as $row) {
            if ((int) $row->daily_pay_entry_id === (int) $payment->daily_pay_entry_id) {
                $result['same_entry'][(int) $row->part_usage_id] = (int) $row->daily_pay_payment_id;
            } else {
                $result['other_entry'][(int) $row->part_usage_id] = (int) $row->daily_pay_entry_id;
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------ Totals

    /**
     * The one place the money is added up. Every component is named and signed
     * explicitly, because this is the arithmetic people will check by hand.
     *
     * Break time is unpaid. Work, travel and parts-run all bill at
     * hourly_payment_rate. `money_owed` is an additive extra, not the total.
     */
    public function computeTotals(DailyPayPayment $payment): void
    {
        $payment->loadMissing('lines');

        $linesTotal = '0.00';
        $lineLabour = '0.00';
        $lineExtras = '0.00';

        foreach ($payment->lines as $line) {
            $rate = $line->hourly_payment_rate ?? $payment->hourly_payment_rate ?? '0';

            $labour = $line->lump_sum !== null
                ? $this->money($line->lump_sum)
                : bcmul($this->payableHours($line), (string) $rate, 2);

            $extras = bcadd($this->money($line->gas), $this->money($line->money_owed), 2);

            // The line's own view includes the parts attributed to its store,
            // because that is what that store cost. The payment roll-up below
            // must NOT re-add them — see the note there.
            $line->line_total = bcadd(bcadd($labour, $extras, 2), $this->money($line->frozen_reimbursable_parts), 2);
            $line->save();

            $linesTotal = bcadd($linesTotal, $line->line_total, 2);
            $lineLabour = bcadd($lineLabour, $labour, 2);
            $lineExtras = bcadd($lineExtras, $extras, 2);
        }

        // A payment-level lump sum replaces the labour its lines worked out,
        // the same way a line's lump sum replaces its own hours x rate.
        $labourComponent = $payment->lump_sum !== null
            ? $this->money($payment->lump_sum)
            : $lineLabour;

        $total = $labourComponent;
        // Line gas and money owed only. Their reimbursable parts are
        // deliberately left out: payment.frozen_reimbursable_parts below is the
        // total over ALL claims, and the per-line figures are the attributed
        // subset of that same number. Adding both pays every receipt twice.
        $total = bcadd($total, $lineExtras, 2);
        $total = bcadd($total, $this->money($payment->gas), 2);
        $total = bcadd($total, $this->money($payment->money_owed), 2);
        $total = bcadd($total, $this->money($payment->frozen_reimbursable_parts), 2);

        $payment->lines_total = $linesTotal;
        $payment->total_amount = $total;
        $payment->save();
    }

    private function payableHours(DailyPayLine $line): string
    {
        $hours = '0.00';

        foreach (self::PAID_BUCKETS as $bucket) {
            $hours = bcadd($hours, $this->money(match ($bucket) {
                'work' => $line->total_working_hours,
                'travel' => $line->travel_time,
                'parts_run' => $line->parts_run_time,
            }), 2);
        }

        return $hours;
    }

    // ----------------------------------------------------------------- Helpers

    /**
     * Every issue this payment covers, mapped to the line that covers it. Built
     * once and reused by both gathers.
     *
     * @return array<int, int>  ticketIssueId => dailyPayLineId
     */
    private function issueToLineMap(DailyPayPayment $payment): array
    {
        $map = [];

        foreach ($payment->lines->sortBy('id') as $line) {
            foreach ($line->ticketIssues as $issue) {
                // First line wins, so the mapping is stable and does not depend
                // on iteration order.
                $map[$issue->id] ??= $line->id;
            }
        }

        return $map;
    }

    /**
     * Which line a record belongs to: the one whose store accounts for most of
     * the issues it covers, breaking ties on the lowest line id so the result
     * is deterministic.
     *
     * Deliberately whole-assignment, never pro-rated. Splitting an amount three
     * ways guarantees a rounding residue and makes the per-store figure
     * impossible to reconcile against the source record.
     *
     * @param  array<int, int>  $coveredIssueIds
     * @param  array<int, int>  $issueToLine
     * @param  bool|null  $spanned  Set true when the record touched more than one line.
     */
    private function attributeToLine(array $coveredIssueIds, array $issueToLine, ?bool &$spanned = null): ?int
    {
        $counts = [];

        foreach ($coveredIssueIds as $issueId) {
            if (isset($issueToLine[$issueId])) {
                $lineId = $issueToLine[$issueId];
                $counts[$lineId] = ($counts[$lineId] ?? 0) + 1;
            }
        }

        $spanned = count($counts) > 1;

        if ($counts === []) {
            // No line owns this — an other_store ticket, or issues outside this
            // payment. It still counts, at payment level. This is exactly what
            // the payment-level money fields are for.
            return null;
        }

        $best = max($counts);
        $tied = array_keys(array_filter($counts, fn (int $c) => $c === $best));
        sort($tied);

        return $tied[0];
    }

    /**
     * @param  array<string, int>  $totals  bucket => minutes
     */
    private function writeFrozenHours(DailyPayPayment $payment, array $totals): void
    {
        $payment->frozen_work_hours = $this->hours($totals['work'] ?? 0);
        $payment->frozen_travel_hours = $this->hours($totals['travel'] ?? 0);
        $payment->frozen_break_hours = $this->hours($totals['break'] ?? 0);
        $payment->frozen_parts_run_hours = $this->hours($totals['parts_run'] ?? 0);
    }

    /**
     * @param  array<int, array<string, int>>  $perLine  lineId => bucket => minutes
     */
    private function writeLineHours(DailyPayPayment $payment, array $perLine): void
    {
        foreach ($payment->lines as $line) {
            $buckets = $perLine[$line->id] ?? [];

            $line->frozen_work_hours = $this->hours($buckets['work'] ?? 0);
            $line->frozen_travel_hours = $this->hours($buckets['travel'] ?? 0);
            $line->frozen_break_hours = $this->hours($buckets['break'] ?? 0);
            $line->frozen_parts_run_hours = $this->hours($buckets['parts_run'] ?? 0);

            // A line whose hours were typed in stays as typed. Without this
            // guard, recalculate() would silently destroy hand corrections —
            // the fastest way to lose trust in the whole feature.
            if (! $line->hours_overridden) {
                $line->total_working_hours = $line->frozen_work_hours;
                $line->travel_time = $line->frozen_travel_hours;
                $line->total_break_time = $line->frozen_break_hours;
                $line->parts_run_time = $line->frozen_parts_run_hours;
            }

            $line->save();
        }
    }

    /**
     * @param  array<int, string>  $perLine  lineId => amount
     */
    private function writeLineParts(DailyPayPayment $payment, array $perLine): void
    {
        foreach ($payment->lines as $line) {
            $line->frozen_reimbursable_parts = $perLine[$line->id] ?? '0.00';
            $line->save();
        }
    }

    private function clearAttendanceClaims(DailyPayPayment $payment): void
    {
        DB::table('daily_pay_payment_attendance_entry')
            ->where('daily_pay_payment_id', $payment->id)
            ->delete();
    }

    private function clearPartClaims(DailyPayPayment $payment): void
    {
        DB::table('daily_pay_payment_part_usage')
            ->where('daily_pay_payment_id', $payment->id)
            ->delete();
    }

    /** Minutes to a decimal(8,2) of hours — the one place the conversion happens. */
    private function hours(int $minutes): string
    {
        return bcdiv((string) $minutes, '60', 2);
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
