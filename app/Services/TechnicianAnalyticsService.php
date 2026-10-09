<?php

namespace App\Services;

use App\Models\AttendanceEntry;
use App\Models\DailyPayLine;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TicketIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Technicians page: what each technician was paid, and the work they did,
 * for a range, a set of stores, issues and categories.
 *
 * PAY comes from the daily pay sheets. Being on a sheet IS being paid, and the
 * pay date is the sheet's date (a DATE), so pay is filtered on local days.
 * Money is never split across issues -- a pay line and a visit can each cover
 * several issues, and the pay sheets never pro-rate -- so with an issue filter
 * the money shown is that of the pay lines that included one of those issues.
 *
 *   paid         = the payments' total_amount (unfiltered)
 *   by store     = each line's share, plus a "payment level" row for what no
 *                  store carries (a payment lump sum, payment gas / money owed,
 *                  parts not tied to a store); together they add up to paid
 *   by kind      = hourly labour, lump sums, gas, money owed, parts reimbursed;
 *                  also adds up to paid
 *
 * A line's labour is read back from what it stored (line_total less its gas,
 * money owed and parts), never recomputed, so these figures are the sheets'.
 *
 * WORK comes from the pay-sheet lines (a day's work at a store, by pay date)
 * and from clocked-in visits no sheet has claimed yet (by clock-in instant --
 * the viewer's local midnights); see work(). A visit's hours come from
 * AttendanceEntry::durations(), the one place clock events become hours.
 */
class TechnicianAnalyticsService
{
    public const MAX_RANGE_DAYS = 400;

    /**
     * @param  array{from: Carbon, to: Carbon, date_from: string, date_to: string, store_ids: array<int, int>, issue_ids: array<int, int>, category_ids: array<int, int>, technician_ids: array<int, int>}  $f
     * @return array<string, mixed>
     */
    public function overview(array $f): array
    {
        $technicians = Technician::withTrashed()
            ->with(['category'])
            ->withCount('coverageStores')
            ->when($f['category_ids'] !== [], fn ($q) => $q->whereIn('category_id', $f['category_ids']))
            ->when($f['technician_ids'] !== [], fn ($q) => $q->whereIn('id', $f['technician_ids']))
            ->orderBy('name')
            ->get();

        $ids = $technicians->pluck('id')->all();
        $pay = $this->pay($ids, $f);
        $work = $this->work($ids, $f);

        $rows = $technicians->map(function (Technician $t) use ($pay, $work) {
            $p = $pay['by_technician'][$t->id] ?? null;
            $w = $work['by_technician'][$t->id] ?? null;

            return [
                'id' => $t->id,
                'name' => $t->name,
                'phone' => $t->phone,
                'location' => $t->location,
                'category' => $t->category ? ['id' => $t->category->id, 'name' => $t->category->name] : null,
                'coverage_count' => (int) $t->coverage_stores_count,
                'rating' => $t->rating,
                'deleted_at' => $t->deleted_at,
                'paid' => $p['paid'] ?? '0.00',
                'pay_days' => $p['pay_days'] ?? 0,
                'visits' => $w['visits'] ?? 0,
                'hours' => $w['hours'] ?? $this->noHours(),
                'issues_worked' => $w['issues'] ?? 0,
                'stores_served' => $w['stores'] ?? 0,
                // The latest clocked visit not yet paid (an instant), and the
                // latest pay sheet with work on it (a day).
                'last_worked_at' => $w['last_visit_at'] ?? null,
                'last_pay_date' => $w['last_pay_date'] ?? null,
            ];
        })->values();

        return [
            'range' => $this->range($f),
            'filtered_money' => $this->moneyFiltered($f),
            'technicians' => $rows->all(),
            'totals' => [
                'paid' => $rows->reduce(fn (string $sum, array $r) => bcadd($sum, $r['paid'], 2), '0.00'),
                'visits' => $rows->sum('visits'),
                'hours' => $this->sumHours($rows->pluck('hours')),
                'technicians_worked' => $rows->filter(fn (array $r) => $r['visits'] > 0 || bccomp($r['paid'], '0', 2) !== 0)->count(),
            ],
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon, date_from: string, date_to: string, store_ids: array<int, int>, issue_ids: array<int, int>, category_ids: array<int, int>, technician_ids: array<int, int>}  $f
     * @return array<string, mixed>
     */
    public function technician(Technician $technician, array $f): array
    {
        $pay = $this->pay([$technician->id], $f);
        $work = $this->work([$technician->id], $f);
        $p = $pay['by_technician'][$technician->id] ?? null;
        $w = $work['by_technician'][$technician->id] ?? null;

        $parts = DB::table('part_usages')
            ->where('paid_by_technician_id', $technician->id)
            ->where('mistaken', false)
            ->where('paid_by', '!=', 'us')
            ->where('created_at', '>=', $f['from'])
            ->where('created_at', '<', $f['to'])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM((quantity - returned_quantity) * unit_cost), 0) as amount')
            ->first();

        $assigned = DB::table('technician_ticket_issue as tti')
            ->join('ticket_issues as ti', 'ti.id', '=', 'tti.ticket_issue_id')
            ->join('tickets as t', 't.id', '=', 'ti.ticket_id')
            ->where('tti.technician_id', $technician->id)
            ->where('tti.created_at', '>=', $f['from'])
            ->where('tti.created_at', '<', $f['to'])
            ->whereNull('t.deleted_at')
            ->when($f['store_ids'] !== [], fn ($q) => $q->whereIn('t.store_id', $f['store_ids']))
            ->when($f['issue_ids'] !== [], fn ($q) => $q->whereIn('ti.issue_id', $f['issue_ids']))
            ->count();

        return [
            'range' => $this->range($f),
            'filtered_money' => $this->moneyFiltered($f),
            'kpis' => [
                'paid' => $p['paid'] ?? '0.00',
                'paid_all_time' => number_format((float) DB::table('daily_pay_payments')->where('technician_id', $technician->id)->sum('total_amount'), 2, '.', ''),
                'pay_days' => $p['pay_days'] ?? 0,
                'visits' => $w['visits'] ?? 0,
                'hours' => $w['hours'] ?? $this->noHours(),
                'issues_worked' => $w['issues'] ?? 0,
                'issues_assigned' => $assigned,
                'stores_served' => $w['stores'] ?? 0,
                'parts_bought' => (int) ($parts->n ?? 0),
                'parts_bought_amount' => number_format((float) ($parts->amount ?? 0), 2, '.', ''),
                'last_worked_at' => $w['last_visit_at'] ?? null,
                'last_pay_date' => $w['last_pay_date'] ?? null,
            ],
            'paid_by_store' => $p['by_store'] ?? [],
            'paid_by_kind' => $p['by_kind'] ?? $this->noKinds(),
            'pay_sheets' => $p['sheets'] ?? [],
            'work_by_store' => $w['by_store'] ?? [],
            'work_by_issue' => $w['by_issue'] ?? [],
            'work_log' => $w['log'] ?? [],
        ];
    }

    // --------------------------------------------------------------------- Pay

    /**
     * @param  array<int, int>  $technicianIds
     * @param  array<string, mixed>  $f
     * @return array{by_technician: array<int, array<string, mixed>>}
     */
    private function pay(array $technicianIds, array $f): array
    {
        if ($technicianIds === []) {
            return ['by_technician' => []];
        }

        $payments = DB::table('daily_pay_payments as p')
            ->join('daily_pay_entries as e', 'e.id', '=', 'p.daily_pay_entry_id')
            ->whereIn('p.technician_id', $technicianIds)
            // Pay dates are days; whereDate so a stored time part never drops the last one.
            ->whereDate('e.date', '>=', $f['date_from'])
            ->whereDate('e.date', '<=', $f['date_to'])
            ->orderBy('e.date')->orderBy('p.id')
            ->get(['p.id', 'p.technician_id', 'p.daily_pay_entry_id', 'p.lump_sum', 'p.gas', 'p.money_owed', 'p.frozen_reimbursable_parts', 'p.total_amount', 'e.date']);

        $lines = DB::table('daily_pay_lines as l')
            ->leftJoin('stores as s', 's.id', '=', 'l.store_id')
            ->whereIn('l.daily_pay_payment_id', $payments->pluck('id'))
            ->orderBy('l.id')
            ->get(['l.id', 'l.daily_pay_payment_id', 'l.store_id', 's.store_number', 'l.other_store', 'l.lump_sum', 'l.gas', 'l.money_owed', 'l.frozen_reimbursable_parts', 'l.line_total'])
            ->groupBy('daily_pay_payment_id');

        // With an issue filter, only the lines that covered one of the issues.
        $issueLines = $f['issue_ids'] === [] ? null : DB::table('daily_pay_line_ticket_issue as x')
            ->join('ticket_issues as ti', 'ti.id', '=', 'x.ticket_issue_id')
            ->whereIn('ti.issue_id', $f['issue_ids'])
            ->whereIn('x.daily_pay_line_id', $lines->flatten(1)->pluck('id'))
            ->pluck('x.daily_pay_line_id')->flip();

        $filtered = $this->moneyFiltered($f);
        $out = [];

        foreach ($payments->groupBy('technician_id') as $technicianId => $own) {
            $paid = '0.00';
            $days = [];
            $byStore = [];
            $kinds = $this->noKinds();
            $sheets = [];

            foreach ($own as $payment) {
                $paymentLump = $payment->lump_sum !== null;
                $carried = '0.00';
                $sheetAmount = '0.00';
                $sheetStores = [];

                foreach ($lines->get($payment->id, collect()) as $line) {
                    if ($f['store_ids'] !== [] && !in_array((int) $line->store_id, $f['store_ids'], true)) {
                        continue;
                    }
                    if ($issueLines !== null && !$issueLines->has($line->id)) {
                        continue;
                    }

                    $gas = $this->m($line->gas);
                    $owed = $this->m($line->money_owed);
                    $parts = $this->m($line->frozen_reimbursable_parts);
                    $labour = bcsub(bcsub(bcsub($this->m($line->line_total), $gas, 2), $owed, 2), $parts, 2);
                    // Under a payment lump sum the line's own labour was not
                    // paid: the lump sum replaced it.
                    $share = $paymentLump ? bcadd(bcadd($gas, $owed, 2), $parts, 2) : $this->m($line->line_total);

                    $key = $line->store_id !== null ? "s:{$line->store_id}" : 'o:'.($line->other_store ?? '');
                    $byStore[$key] ??= [
                        'store_id' => $line->store_id,
                        'store_number' => $line->store_number,
                        'other_store' => $line->store_id === null ? $line->other_store : null,
                        'amount' => '0.00',
                        'lines' => 0,
                    ];
                    $byStore[$key]['amount'] = bcadd($byStore[$key]['amount'], $share, 2);
                    $byStore[$key]['lines']++;

                    if (!$paymentLump) {
                        $bucket = $line->lump_sum !== null ? 'lump_sums' : 'hourly_labour';
                        $kinds[$bucket] = bcadd($kinds[$bucket], $labour, 2);
                    }
                    $kinds['gas'] = bcadd($kinds['gas'], $gas, 2);
                    $kinds['money_owed'] = bcadd($kinds['money_owed'], $owed, 2);
                    if ($filtered) {
                        // Unfiltered, parts are counted once from the payment below.
                        $kinds['parts_reimbursed'] = bcadd($kinds['parts_reimbursed'], $parts, 2);
                    }

                    $carried = bcadd($carried, $share, 2);
                    $sheetStores[] = $line->store_number ?? $line->other_store;
                }

                if (!$filtered) {
                    $total = $this->m($payment->total_amount);
                    $rest = bcsub($total, $carried, 2);
                    if (bccomp($rest, '0', 2) !== 0) {
                        $byStore['payment'] ??= ['store_id' => null, 'store_number' => null, 'other_store' => null, 'payment_level' => true, 'amount' => '0.00', 'lines' => 0];
                        $byStore['payment']['amount'] = bcadd($byStore['payment']['amount'], $rest, 2);
                    }
                    if ($paymentLump) {
                        $kinds['lump_sums'] = bcadd($kinds['lump_sums'], $this->m($payment->lump_sum), 2);
                    }
                    $kinds['gas'] = bcadd($kinds['gas'], $this->m($payment->gas), 2);
                    $kinds['money_owed'] = bcadd($kinds['money_owed'], $this->m($payment->money_owed), 2);
                    $kinds['parts_reimbursed'] = bcadd($kinds['parts_reimbursed'], $this->m($payment->frozen_reimbursable_parts), 2);
                    $sheetAmount = $total;
                } else {
                    $sheetAmount = $carried;
                }

                if ($filtered && $sheetStores === []) {
                    continue; // nothing on this sheet matched the filters
                }

                $paid = bcadd($paid, $sheetAmount, 2);
                $days[(string) $payment->date] = true;
                $sheets[] = [
                    'daily_pay_entry_id' => (int) $payment->daily_pay_entry_id,
                    'daily_pay_payment_id' => (int) $payment->id,
                    'date' => substr((string) $payment->date, 0, 10),
                    'stores' => array_values(array_unique(array_filter($sheetStores))),
                    'amount' => $sheetAmount,
                ];
            }

            $out[(int) $technicianId] = [
                'paid' => $paid,
                'pay_days' => count($days),
                'by_store' => collect($byStore)
                    ->map(fn (array $row) => $row + ['payment_level' => false])
                    ->sortByDesc(fn (array $row) => (float) $row['amount'])
                    ->values()->all(),
                'by_kind' => $kinds,
                'sheets' => array_reverse($sheets),
            ];
        }

        return ['by_technician' => $out];
    }

    // -------------------------------------------------------------------- Work

    /**
     * The work, from two places, never counting the same work twice:
     *
     *   pay-sheet lines   each line is a day's work at one store, with the hours
     *                     and the issues the sheet records (by pay date). Most
     *                     work only ever exists here: hours typed onto the sheet.
     *   unpaid visits     clocked-in visits (by clock-in instant) that no pay
     *                     sheet has claimed yet. Once a sheet claims a visit, its
     *                     hours are on that sheet's line, so the line stands for it.
     *
     * @param  array<int, int>  $technicianIds
     * @param  array<string, mixed>  $f
     * @return array{by_technician: array<int, array<string, mixed>>}
     */
    private function work(array $technicianIds, array $f): array
    {
        if ($technicianIds === []) {
            return ['by_technician' => []];
        }

        $jobs = [];

        $lines = DailyPayLine::query()
            ->whereIn('technician_id', $technicianIds)
            ->whereHas('entry', fn ($q) => $q->whereDate('date', '>=', $f['date_from'])->whereDate('date', '<=', $f['date_to']))
            ->when($f['store_ids'] !== [], fn ($q) => $q->whereIn('store_id', $f['store_ids']))
            ->when($f['issue_ids'] !== [], fn ($q) => $q->whereHas('ticketIssues', fn ($t) => $t->whereIn('issue_id', $f['issue_ids'])))
            ->with(['entry', 'store', 'ticketIssues.issue', 'ticketIssues.ticket.store'])
            ->get();

        foreach ($lines as $line) {
            $date = $line->entry->date->format('Y-m-d');
            $jobs[] = [
                'technician_id' => (int) $line->technician_id,
                'sort' => $date.'T23:59:59',
                'source' => 'pay_sheet',
                'attendance_entry_id' => null,
                'daily_pay_entry_id' => (int) $line->daily_pay_entry_id,
                'date' => $date,
                'start' => null,
                'end' => null,
                'store' => $line->store?->store_number ?? ($line->other_store ?: 'Other location'),
                'issues' => $line->ticketIssues
                    ->filter(fn (TicketIssue $ti) => $f['issue_ids'] === [] || in_array((int) $ti->issue_id, $f['issue_ids'], true))
                    ->values(),
                'hours' => [
                    'work' => round((float) $line->total_working_hours, 2),
                    'travel' => round((float) $line->travel_time, 2),
                    'parts_run' => round((float) $line->parts_run_time, 2),
                    'break' => round((float) $line->total_break_time, 2),
                ],
                'paid' => true,
            ];
        }

        $visits = AttendanceEntry::query()
            ->whereIn('technician_id', $technicianIds)
            ->where('mistaken', false)
            ->where('start_clock', '>=', $f['from'])
            ->where('start_clock', '<', $f['to'])
            ->whereDoesntHave('dailyPayPayments')
            ->with(['events', 'ticketIssues.issue', 'ticketIssues.ticket.store'])
            ->get();

        foreach ($visits as $entry) {
            /** @var Collection<int, TicketIssue> $matching */
            $matching = $entry->ticketIssues->filter(fn (TicketIssue $ti) => $this->matches($ti, $f))->values();
            if ($matching->isEmpty() && ($f['store_ids'] !== [] || $f['issue_ids'] !== [])) {
                continue;
            }

            $d = $entry->durations();
            $jobs[] = [
                'technician_id' => (int) $entry->technician_id,
                'sort' => $entry->start_clock->copy()->utc()->format('Y-m-d\TH:i:s'),
                'source' => 'visit',
                'attendance_entry_id' => $entry->id,
                'daily_pay_entry_id' => null,
                'date' => null,
                'start' => $entry->start_clock,
                'end' => $entry->end_clock,
                // A visit can span stores; each of its issues says where.
                'store' => null,
                'issues' => $matching,
                'hours' => [
                    'work' => round($d['work'] / 60, 2),
                    'travel' => round($d['travel'] / 60, 2),
                    'parts_run' => round($d['parts_run'] / 60, 2),
                    'break' => round($d['break'] / 60, 2),
                ],
                'paid' => false,
            ];
        }

        $out = [];

        foreach (collect($jobs)->groupBy('technician_id') as $technicianId => $own) {
            $hours = $this->noHours();
            $issues = [];
            $stores = [];
            $byStore = [];
            $byIssue = [];
            $log = [];
            $lastVisitAt = null;
            $lastPayDate = null;

            foreach ($own->sortByDesc('sort') as $job) {
                $h = $job['hours'];
                $paidHours = round($h['work'] + $h['travel'] + $h['parts_run'], 2);
                foreach ($h as $k => $v) {
                    $hours[$k] = round($hours[$k] + $v, 2);
                }
                if ($job['source'] === 'visit') {
                    $lastVisitAt ??= $job['start'];
                } else {
                    $lastPayDate ??= $job['date'];
                }

                // Where: the line's store, or each of the visit's issues' stores.
                $jobStores = $job['store'] !== null
                    ? [$job['store'] => $job['issues']->pluck('id')->all()]
                    : $job['issues']->groupBy(fn (TicketIssue $ti) => $this->storeLabel($ti))->map(fn ($g) => $g->pluck('id')->all())->all();

                foreach ($jobStores as $storeKey => $issueIds) {
                    $stores[$storeKey] = true;
                    $byStore[$storeKey] ??= ['store' => (string) $storeKey, 'visits' => 0, 'hours' => 0.0, 'issues' => []];
                    $byStore[$storeKey]['visits']++;
                    $byStore[$storeKey]['hours'] = round($byStore[$storeKey]['hours'] + $paidHours, 2);
                    foreach ($issueIds as $id) {
                        $byStore[$storeKey]['issues'][$id] = true;
                    }
                }

                foreach ($job['issues'] as $ti) {
                    $issues[$ti->id] = true;
                    $issueKey = $ti->issue_id !== null ? "i:{$ti->issue_id}" : 'other';
                    $byIssue[$issueKey] ??= [
                        'issue_id' => $ti->issue_id,
                        'title' => $ti->issue_id !== null ? $ti->displayTitle() : 'Other (not in the catalog)',
                        'visits' => 0,
                        'tickets' => [],
                        'hours' => 0.0,
                    ];
                    $byIssue[$issueKey]['visits']++;
                    $byIssue[$issueKey]['tickets'][$ti->ticket_id] = true;
                    $byIssue[$issueKey]['hours'] = round($byIssue[$issueKey]['hours'] + $paidHours, 2);
                }

                $log[] = [
                    'source' => $job['source'],
                    'attendance_entry_id' => $job['attendance_entry_id'],
                    'daily_pay_entry_id' => $job['daily_pay_entry_id'],
                    'date' => $job['date'],
                    'start' => $job['start'],
                    'end' => $job['end'],
                    'stores' => array_map('strval', array_keys($jobStores)),
                    'tickets' => $job['issues']->map(fn (TicketIssue $ti) => [
                        'ticket_id' => $ti->ticket_id,
                        'store_number' => $ti->ticket?->store?->store_number,
                        'title' => $ti->displayTitle(),
                    ])->values()->all(),
                    'hours' => $h,
                    'paid' => $job['paid'],
                ];
            }

            $out[(int) $technicianId] = [
                'visits' => count($log),
                'hours' => $hours,
                'issues' => count($issues),
                'stores' => count($stores),
                'last_visit_at' => $lastVisitAt,
                'last_pay_date' => $lastPayDate,
                'by_store' => collect($byStore)->map(fn (array $r) => [
                    'store' => $r['store'],
                    'visits' => $r['visits'],
                    'hours' => $r['hours'],
                    'issues' => count($r['issues']),
                ])->sortByDesc('visits')->values()->all(),
                'by_issue' => collect($byIssue)->map(fn (array $r) => [
                    'issue_id' => $r['issue_id'],
                    'title' => $r['title'],
                    'visits' => $r['visits'],
                    'tickets' => count($r['tickets']),
                    'hours' => $r['hours'],
                ])->sortByDesc('visits')->values()->all(),
                'log' => $log,
            ];
        }

        return ['by_technician' => $out];
    }

    /** A ticket issue counts when it is at one of the stores and one of the issues asked for. */
    private function matches(TicketIssue $ti, array $f): bool
    {
        if ($ti->ticket === null || $ti->ticket->deleted_at !== null) {
            return false;
        }
        if ($f['store_ids'] !== [] && !in_array((int) $ti->ticket->store_id, $f['store_ids'], true)) {
            return false;
        }

        return $f['issue_ids'] === [] || in_array((int) $ti->issue_id, $f['issue_ids'], true);
    }

    private function storeLabel(TicketIssue $ti): string
    {
        return $ti->ticket?->store?->store_number ?? ($ti->ticket?->other_store ?: 'Other location');
    }

    // ----------------------------------------------------------------- Helpers

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    public function storeIds(array $codes): array
    {
        return $codes === [] ? [] : Store::query()->whereIn('store_number', $codes)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** With a store or issue filter, money is read from the matching pay lines only. */
    private function moneyFiltered(array $f): bool
    {
        return $f['store_ids'] !== [] || $f['issue_ids'] !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function range(array $f): array
    {
        return ['from' => $f['from'], 'to' => $f['to'], 'date_from' => $f['date_from'], 'date_to' => $f['date_to']];
    }

    /**
     * @return array{work: float, travel: float, parts_run: float, break: float}
     */
    private function noHours(): array
    {
        return ['work' => 0.0, 'travel' => 0.0, 'parts_run' => 0.0, 'break' => 0.0];
    }

    /**
     * @return array<string, string>
     */
    private function noKinds(): array
    {
        return ['hourly_labour' => '0.00', 'lump_sums' => '0.00', 'gas' => '0.00', 'money_owed' => '0.00', 'parts_reimbursed' => '0.00'];
    }

    /**
     * @param  Collection<int, array<string, float>>  $all
     * @return array<string, float>
     */
    private function sumHours(Collection $all): array
    {
        $sum = $this->noHours();
        foreach ($all as $h) {
            foreach ($sum as $k => $v) {
                $sum[$k] = round($v + ($h[$k] ?? 0), 2);
            }
        }

        return $sum;
    }

    private function m(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
