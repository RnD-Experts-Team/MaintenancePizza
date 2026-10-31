<?php

namespace App\Services\LegacyConversion;

use Illuminate\Support\Facades\DB;

/**
 * Daily Pay sheets and one-off pay entries become payments.
 *
 * Each old PAYMENT (one technician on one sheet) becomes one payment dated
 * the sheet's date, and its money is split into typed lines the way the old
 * sheet worked it out:
 *
 * - a payment-level lump sum ("one price for the day") → one Inspection line,
 *   which replaces the labour of its stores, as the lump sum did;
 * - a store line with its own lump sum → a Fixed price line;
 * - otherwise → a By-the-hour line, (work + travel + parts run) x rate,
 *   rounded to the cent (half up, as the old sheet did);
 * - the attendance an old payment claimed → the trip sessions it became,
 *   each linked once, to the line that paid the time of the old line the
 *   claim named (hourly, fixed, or the day's one price);
 * - gas → Gas lines; money owed → Other lines (a negative one, a deduction,
 *   is kept as a negative line);
 * - parts the technician paid for → Parts lines, each also recorded as a
 *   technician purchase that this line paid back.
 *
 * The new total must equal the old `total_amount`. If rounding in old data
 * makes them differ, an Other line "Adjusted to match the old pay sheet"
 * carries the difference and the report says so.
 */
final class PayToPayments extends Converter
{
    public function run(): void
    {
        DB::table('daily_pay_payments')->orderBy('id')->chunkById(100, function ($payments) {
            foreach ($payments as $payment) {
                if (LegacyMap::has('daily_pay_payments', (int) $payment->id, 'payments')) {
                    continue;
                }

                DB::transaction(fn () => $this->sheetPayment($payment));
            }
        });

        DB::table('pay_entries')->orderBy('id')->chunkById(100, function ($entries) {
            foreach ($entries as $entry) {
                if (LegacyMap::has('pay_entries', (int) $entry->id, 'payments')) {
                    continue;
                }

                DB::transaction(fn () => $this->payEntry($entry));
            }
        });
    }

    private function sheetPayment(object $p): void
    {
        $entry = DB::table('daily_pay_entries')->where('id', $p->daily_pay_entry_id)->first();
        $lines = DB::table('daily_pay_lines')->where('daily_pay_payment_id', $p->id)->orderBy('id')->get();

        $paymentId = (int) DB::table('payments')->insertGetId([
            'paid_at' => $entry->date,
            'technician_id' => $p->technician_id,
            'method' => null,
            'total' => 0,
            'created_by' => $p->created_by ?? $entry->created_by,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ]);
        LegacyMap::put('daily_pay_payments', (int) $p->id, 'payments', $paymentId);

        $firstStore = $lines->first();
        $order = 0;
        $issuesByLine = [];
        foreach ($lines as $l) {
            $issuesByLine[$l->id] = DB::table('daily_pay_line_ticket_issue')->where('daily_pay_line_id', $l->id)->pluck('ticket_issue_id')->map(fn ($id) => (int) $id)->all();
        }

        // Old line id => the new line that paid its time (hourly, fixed, or
        // the day's one price): where its attendance claims go.
        $timeLineByOld = [];

        if ($p->lump_sum !== null) {
            $allIssues = array_values(array_unique(array_merge([], ...array_values($issuesByLine))));
            $stores = $lines->map(fn ($l) => $l->store_id ?? $l->other_store)->unique()->count();
            $lineId = $this->line($paymentId, $order++, 'inspection', $firstStore, $this->money($p->lump_sum), [
                'description' => $stores > 1 ? 'One price for the day, covering '.$stores.' stores' : 'One price for the day',
            ]);
            $this->linkIssues($lineId, $allIssues);
            $timeLineByOld = $lines->mapWithKeys(fn ($l) => [(int) $l->id => $lineId])->all();
        }

        foreach ($lines as $l) {
            if ($p->lump_sum === null) {
                if ($l->lump_sum !== null) {
                    $lineId = $this->line($paymentId, $order++, 'fixed', $l, $this->money($l->lump_sum), []);
                } else {
                    $hours = $this->money(bcadd(bcadd($this->decimal($l->total_working_hours), $this->decimal($l->travel_time), 8), $this->decimal($l->parts_run_time), 8));
                    $rate = $this->decimal($l->hourly_payment_rate ?? $p->hourly_payment_rate ?? '0');
                    $lineId = $this->line($paymentId, $order++, 'hourly', $l, $this->times($hours, $rate), [
                        'hours' => $hours,
                        'rate' => $rate,
                        'hours_overridden' => (bool) $l->hours_overridden,
                    ]);
                }
                $timeLineByOld[(int) $l->id] = $lineId;
                $this->linkIssues($lineId, $issuesByLine[$l->id]);
            }

            if ($this->positive($l->gas)) {
                $this->linkIssues($this->line($paymentId, $order++, 'gas', $l, $this->money($l->gas), []), $issuesByLine[$l->id]);
            }
            // Money owed can be either way (a deduction is negative): kept as is.
            if ($this->nonZero($l->money_owed)) {
                $this->linkIssues($this->line($paymentId, $order++, 'other', $l, $this->money($l->money_owed), ['description' => 'Money owed']), $issuesByLine[$l->id]);
            }

            $this->moveMorphs('App\\Models\\DailyPayLine', (int) $l->id, 'App\\Models\\Payment', $paymentId);
        }

        if ($this->positive($p->gas)) {
            $this->line($paymentId, $order++, 'gas', $firstStore, $this->money($p->gas), ['description' => 'Gas for the day']);
        }
        if ($this->nonZero($p->money_owed)) {
            $this->line($paymentId, $order++, 'other', $firstStore, $this->money($p->money_owed), ['description' => 'Money owed']);
        }

        $this->sessions($p, $paymentId, $timeLineByOld);
        $this->reimbursedParts($p, $paymentId, $order, $lines, $firstStore);
        $this->settle($paymentId, $p->total_amount, "daily pay payment #{$p->id}", $firstStore);

        $this->moveMorphs('App\\Models\\DailyPayPayment', (int) $p->id, 'App\\Models\\Payment', $paymentId);
        $this->moveMorphs('App\\Models\\DailyPayEntry', (int) $entry->id, 'App\\Models\\Payment', $paymentId, copy: true);
    }

    /**
     * The attendance the old payment claimed → its trip sessions, each paid
     * by the line that paid the time of the old line the claim named (else
     * the payment's first time line, else its first line: a claim must not be
     * dropped, or the session would show as unpaid and be paid twice).
     *
     * @param  array<int, int>  $timeLineByOld  old line id => new time line id
     */
    private function sessions(object $p, int $paymentId, array $timeLineByOld): void
    {
        $claims = DB::table('daily_pay_payment_attendance_entry')->where('daily_pay_payment_id', $p->id)->orderBy('id')->get();
        $fallback = $timeLineByOld === [] ? null : reset($timeLineByOld);

        foreach ($claims as $claim) {
            $lineId = ($claim->daily_pay_line_id !== null ? ($timeLineByOld[(int) $claim->daily_pay_line_id] ?? null) : null)
                ?? $fallback
                ?? DB::table('payment_lines')->where('payment_id', $paymentId)->orderBy('sort_order')->value('id');

            if ($lineId === null) {
                LegacyMap::report('payments', "Daily pay payment #{$p->id} claimed attendance entry #{$claim->attendance_entry_id} but has no line to hang it on; its sessions stay unpaid", ['attendance_entry_id' => $claim->attendance_entry_id], 'error');

                continue;
            }

            $minutes = (int) $claim->work_minutes + (int) $claim->travel_minutes + (int) $claim->parts_run_minutes;
            foreach ($this->sessionsOf((int) $claim->attendance_entry_id) as $i => $sessionId) {
                $inserted = DB::table('payment_line_trip_session')->insertOrIgnore([
                    'payment_line_id' => $lineId,
                    'trip_session_id' => $sessionId,
                    'paid_minutes' => $i === 0 ? $minutes : 0,
                    'created_at' => $claim->created_at,
                    'updated_at' => $claim->updated_at,
                ]);

                if ($inserted === 0) {
                    LegacyMap::report('payments', "Trip session #{$sessionId} was claimed by more than one old pay sheet; only the first keeps it", ['attendance_entry_id' => $claim->attendance_entry_id], 'warning');
                }
            }
        }
    }

    /**
     * The sessions an attendance entry became, first clock-in first: every
     * session of the trip it became. Read from the trip, not from the map:
     * legacy_conversion_map is unique on (legacy row, new table), so it only ever
     * holds an entry's FIRST session, and the others would be left unpaid.
     *
     * @return list<int>
     */
    private function sessionsOf(int $attendanceEntryId): array
    {
        $tripId = LegacyMap::get('attendance_entries', $attendanceEntryId, 'trips');

        if ($tripId === null) {
            return [];
        }

        return DB::table('trip_sessions')
            ->where('trip_id', $tripId)
            ->orderBy('clock_in')->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function reimbursedParts(object $p, int $paymentId, int $order, $lines, ?object $firstStore): void
    {
        $claims = DB::table('daily_pay_payment_part_usage')->where('daily_pay_payment_id', $p->id)->orderBy('id')->get();

        foreach ($claims as $claim) {
            $usage = DB::table('part_usages')->where('id', $claim->part_usage_id)->first();
            $issues = DB::table('part_ticket_issue')->where('part_usage_id', $usage->id)->pluck('ticket_issue_id')->map(fn ($id) => (int) $id)->all();
            $where = $lines->firstWhere('id', $claim->daily_pay_line_id)
                ?? ($claim->store_id !== null ? (object) ['store_id' => $claim->store_id, 'other_store' => null] : $firstStore);

            $lineId = $this->line($paymentId, $order++, 'parts', $where, $this->money($claim->amount), [
                'description' => 'Parts the technician paid for',
                'part_id' => $usage->part_id,
                'quantity' => $usage->quantity,
                'unit_cost' => $usage->unit_cost,
            ]);
            $this->linkIssues($lineId, $issues);

            // Paid back on two old sheets: the money stays on both payments
            // (it was paid twice), the purchase is recorded once.
            if (LegacyMap::has('part_usages', (int) $usage->id, 'technician_purchases')) {
                LegacyMap::report('payments', "Part usage #{$usage->id} was paid back by more than one old pay sheet; the purchase stays on the first", ['part_usage_id' => $usage->id, 'daily_pay_payment_id' => $p->id], 'warning');

                continue;
            }

            $purchaseId = (int) DB::table('technician_purchases')->insertGetId([
                'technician_id' => $usage->paid_by_technician_id ?? $p->technician_id,
                'ticket_issue_id' => $issues[0] ?? null,
                'part_id' => $usage->part_id,
                'description' => DB::table('parts')->where('id', $usage->part_id)->value('name') ?? 'Part',
                'quantity' => $usage->quantity,
                'total_cost' => $this->money($usage->cost),
                'bought_at' => $usage->created_at,
                'payment_line_id' => $lineId,
                'mistaken' => $usage->mistaken,
                'created_by' => $usage->created_by,
                'created_at' => $usage->created_at,
                'updated_at' => $usage->updated_at,
            ]);
            LegacyMap::put('part_usages', (int) $usage->id, 'technician_purchases', $purchaseId);
        }
    }

    private function payEntry(object $e): void
    {
        $issues = DB::table('pay_entry_ticket_issue')->where('pay_entry_id', $e->id)->pluck('ticket_issue_id')->map(fn ($id) => (int) $id)->all();
        [$storeId, $other] = $issues === [] ? [null, 'Unknown store'] : $this->storeOfIssue($issues[0]);
        $where = (object) ['store_id' => $storeId, 'other_store' => $storeId === null ? ($other ?? 'Unknown store') : null];

        $paymentId = (int) DB::table('payments')->insertGetId([
            'paid_at' => substr((string) $e->created_at, 0, 10),
            'technician_id' => $e->technician_id,
            'total' => 0,
            'mistaken' => $e->mistaken,
            'created_by' => $e->created_by,
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ]);
        LegacyMap::put('pay_entries', (int) $e->id, 'payments', $paymentId);

        $order = 0;
        $work = bcadd($this->money($e->base_pay), $this->money($e->performance_pay), 2);
        if ($this->positive($work)) {
            $this->linkIssues($this->line($paymentId, $order++, 'fixed', $where, $work, [
                'description' => "Base {$this->money($e->base_pay)} + performance {$this->money($e->performance_pay)}",
            ]), $issues);
        }

        $mileage = $this->times($e->miles_driven, $e->per_mile_rate);
        $driving = bcadd(bcadd($this->money($e->driving_base_pay), $this->money($e->driving_performance_pay), 2), $mileage, 2);
        if ($this->positive($driving)) {
            $this->linkIssues($this->line($paymentId, $order++, 'gas', $where, $driving, [
                'description' => 'Driving pay',
                'miles' => $e->miles_driven,
                'rate' => $e->per_mile_rate,
            ]), $issues);
        }

        if ($order === 0) {
            LegacyMap::report('payments', "Pay entry #{$e->id} had no amounts; kept as an empty payment", [], 'warning');
        }

        $this->settle($paymentId, null, "pay entry #{$e->id}", $where);
        $this->moveMorphs('App\\Models\\PayEntry', (int) $e->id, 'App\\Models\\Payment', $paymentId);
    }

    /** @param  array<string, mixed>  $extra */
    private function line(int $paymentId, int $order, string $type, ?object $where, string $amount, array $extra): int
    {
        $storeId = $where->store_id ?? null;

        return (int) DB::table('payment_lines')->insertGetId(array_merge([
            'payment_id' => $paymentId,
            'type' => $type,
            'store_id' => $storeId,
            'other_store' => $storeId === null ? ($where->other_store ?? 'Unknown store') : null,
            'amount' => $amount,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @param  list<int>  $issueIds */
    private function linkIssues(int $lineId, array $issueIds): void
    {
        foreach (array_unique($issueIds) as $issueId) {
            DB::table('payment_line_ticket_issue')->insertOrIgnore([
                'payment_line_id' => $lineId,
                'ticket_issue_id' => $issueId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Store the total, matching the old figure exactly when there was one. */
    private function settle(int $paymentId, mixed $oldTotal, string $what, ?object $where): void
    {
        $sum = $this->money(DB::table('payment_lines')->where('payment_id', $paymentId)->sum('amount'));

        if ($oldTotal !== null && bccomp($sum, $this->money($oldTotal), 2) !== 0) {
            $diff = bcsub($this->money($oldTotal), $sum, 2);
            $order = (int) DB::table('payment_lines')->where('payment_id', $paymentId)->max('sort_order') + 1;
            $this->line($paymentId, $order, 'other', $where, $diff, ['description' => 'Adjusted to match the old pay sheet']);
            LegacyMap::report('payments', "The lines of {$what} came to {$sum}, the old sheet said {$this->money($oldTotal)}; added an adjustment of {$diff}", [], 'warning');
            $sum = $this->money($oldTotal);
        }

        DB::table('payments')->where('id', $paymentId)->update(['total' => $sum]);
    }
}
