<?php

namespace App\Services;

use App\Enums\PaymentLineType;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Models\TripSession;
use App\Models\TripStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Technicians page: what each technician was paid, and the work they did,
 * for a range, a set of stores, issues and categories.
 *
 * PAY comes from payments (docs/MAINTENANCE.md §4) made to the
 * technician, mistaken ones left out. The pay date is the payment's
 * `paid_at` (a DATE), so pay is filtered on local days. Every penny of a
 * payment is on one of its lines, and each line is for one store (or one
 * storage location), so with no filter
 *
 *   paid        = the payments' totals
 *   by store    = the lines per store / storage location   — adds up to paid
 *   by kind     = the lines per type (hourly, fixed, ...)   — adds up to paid
 *
 * With a store or issue filter, only the matching lines count (a line matches
 * an issue filter when it is linked to one of those issues). Money is never
 * split across issues.
 *
 * WORK comes from trip sessions (clock-in → clock-out), by clock-in instant
 * -- the viewer's local midnights. A session's issues are those on its trip's
 * stops; its hours come from TripService::durations(), the one place clock
 * events become minutes. `paid` says whether a payment line has claimed it.
 */
class TechnicianAnalyticsService
{
    public const MAX_RANGE_DAYS = 400;

    public function __construct(private TripService $trips)
    {
    }

    /**
     * @param  array{from: Carbon, to: Carbon, date_from: string, date_to: string, store_ids: array<int, int>, issue_ids: array<int, int>, category_ids: array<int, int>, technician_ids: array<int, int>}  $f
     * @return array<string, mixed>
     */
    public function overview(array $f): array
    {
        $technicians = Technician::withTrashed()
            ->with(['category', 'abilities'])
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
                // The average of the per-issue ratings (there is no other).
                'rating' => $t->averageRating(),
                'emergency_available' => (bool) $t->emergency_available,
                'deleted_at' => $t->deleted_at,
                'paid' => $p['paid'] ?? '0.00',
                'pay_days' => $p['pay_days'] ?? 0,
                'visits' => $w['visits'] ?? 0,
                'hours' => $w['hours'] ?? $this->noHours(),
                'issues_worked' => $w['issues'] ?? 0,
                'stores_served' => $w['stores'] ?? 0,
                // The latest session clocked in (an instant), and the latest
                // payment (a day).
                'last_worked_at' => $w['last_visit_at'] ?? null,
                'last_pay_date' => $p['last_pay_date'] ?? null,
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

        // What he bought with his own money in the range (paid back or not).
        $parts = DB::table('technician_purchases')
            ->where('technician_id', $technician->id)
            ->where('mistaken', false)
            ->where('bought_at', '>=', $f['from'])
            ->where('bought_at', '<', $f['to'])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total_cost), 0) as amount')
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
                'paid_all_time' => number_format((float) DB::table('payments')
                    ->where('technician_id', $technician->id)
                    ->where('mistaken', false)
                    ->sum('total'), 2, '.', ''),
                'pay_days' => $p['pay_days'] ?? 0,
                'visits' => $w['visits'] ?? 0,
                'hours' => $w['hours'] ?? $this->noHours(),
                'issues_worked' => $w['issues'] ?? 0,
                'issues_assigned' => $assigned,
                'stores_served' => $w['stores'] ?? 0,
                'parts_bought' => (int) ($parts->n ?? 0),
                'parts_bought_amount' => number_format((float) ($parts->amount ?? 0), 2, '.', ''),
                'last_worked_at' => $w['last_visit_at'] ?? null,
                'last_pay_date' => $p['last_pay_date'] ?? null,
            ],
            'paid_by_store' => $p['by_store'] ?? [],
            'paid_by_kind' => $p['by_kind'] ?? $this->noKinds(),
            'pay_sheets' => $p['payments'] ?? [],
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

        $payments = DB::table('payments as p')
            ->whereIn('p.technician_id', $technicianIds)
            ->where('p.mistaken', false)
            // Pay dates are days; whereDate so a stored time part never drops the last one.
            ->whereDate('p.paid_at', '>=', $f['date_from'])
            ->whereDate('p.paid_at', '<=', $f['date_to'])
            ->orderBy('p.paid_at')->orderBy('p.id')
            ->get(['p.id', 'p.technician_id', 'p.paid_at', 'p.method', 'p.invoice_number', 'p.total']);

        $lines = DB::table('payment_lines as l')
            ->leftJoin('stores as s', 's.id', '=', 'l.store_id')
            ->leftJoin('storage_locations as sl', 'sl.id', '=', 'l.storage_location_id')
            ->whereIn('l.payment_id', $payments->pluck('id'))
            ->orderBy('l.sort_order')->orderBy('l.id')
            ->get(['l.id', 'l.payment_id', 'l.type', 'l.store_id', 's.store_number', 'l.other_store', 'l.storage_location_id', 'sl.name as storage_name', 'l.amount'])
            ->groupBy('payment_id');

        // With an issue filter, only the lines linked to one of the issues.
        $issueLines = $f['issue_ids'] === [] ? null : DB::table('payment_line_ticket_issue as x')
            ->join('ticket_issues as ti', 'ti.id', '=', 'x.ticket_issue_id')
            ->whereIn('ti.issue_id', $f['issue_ids'])
            ->whereIn('x.payment_line_id', $lines->flatten(1)->pluck('id'))
            ->pluck('x.payment_line_id')->flip();

        $out = [];

        foreach ($payments->groupBy('technician_id') as $technicianId => $own) {
            $paid = '0.00';
            $days = [];
            $byStore = [];
            $kinds = $this->noKinds();
            $list = [];
            $lastPayDate = null;

            foreach ($own as $payment) {
                $amount = '0.00';
                $stores = [];

                foreach ($lines->get($payment->id, collect()) as $line) {
                    if ($f['store_ids'] !== [] && ! in_array((int) $line->store_id, $f['store_ids'], true)) {
                        continue;
                    }
                    if ($issueLines !== null && ! $issueLines->has($line->id)) {
                        continue;
                    }

                    $share = $this->m($line->amount);
                    $key = match (true) {
                        $line->store_id !== null => "s:{$line->store_id}",
                        $line->storage_location_id !== null => "l:{$line->storage_location_id}",
                        default => 'o:'.($line->other_store ?? ''),
                    };
                    $byStore[$key] ??= [
                        'store_id' => $line->store_id,
                        'store_number' => $line->store_number,
                        'other_store' => $line->store_id === null && $line->storage_location_id === null ? $line->other_store : null,
                        'storage_location' => $line->storage_location_id !== null
                            ? ['id' => (int) $line->storage_location_id, 'name' => $line->storage_name]
                            : null,
                        'amount' => '0.00',
                        'lines' => 0,
                    ];
                    $byStore[$key]['amount'] = bcadd($byStore[$key]['amount'], $share, 2);
                    $byStore[$key]['lines']++;

                    if (array_key_exists($line->type, $kinds)) {
                        $kinds[$line->type] = bcadd($kinds[$line->type], $share, 2);
                    }

                    $amount = bcadd($amount, $share, 2);
                    $stores[] = $line->store_number ?? $line->other_store ?? $line->storage_name;
                }

                if ($this->moneyFiltered($f) && $stores === []) {
                    continue; // nothing on this payment matched the filters
                }

                $date = substr((string) $payment->paid_at, 0, 10);
                $paid = bcadd($paid, $amount, 2);
                $days[$date] = true;
                $lastPayDate = $lastPayDate === null || $date > $lastPayDate ? $date : $lastPayDate;
                $list[] = [
                    'payment_id' => (int) $payment->id,
                    'date' => $date,
                    'method' => $payment->method,
                    'invoice_number' => $payment->invoice_number,
                    'stores' => array_values(array_unique(array_filter($stores))),
                    'amount' => $amount,
                ];
            }

            $out[(int) $technicianId] = [
                'paid' => $paid,
                'pay_days' => count($days),
                'last_pay_date' => $lastPayDate,
                'by_store' => collect($byStore)
                    ->sortByDesc(fn (array $row) => (float) $row['amount'])
                    ->values()->all(),
                'by_kind' => $kinds,
                'payments' => array_reverse($list),
            ];
        }

        return ['by_technician' => $out];
    }

    // -------------------------------------------------------------------- Work

    /**
     * Every session (not mistaken) clocked in inside the range. With a store or
     * issue filter, a session counts when one of its trip's stops matches,
     * and only the matching stops and issues are reported on it.
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

        $sessions = TripSession::query()
            ->whereIn('technician_id', $technicianIds)
            ->where('mistaken', false)
            ->where('clock_in', '>=', $f['from'])
            ->where('clock_in', '<', $f['to'])
            ->with([
                'events',
                'paymentLines',
                'trip.stops' => fn ($q) => $q->where('mistaken', false),
                'trip.stops.store',
                'trip.stops.ticketIssues.issue',
                'trip.stops.ticketIssues.ticket.store',
            ])
            ->get();

        $filtered = $f['store_ids'] !== [] || $f['issue_ids'] !== [];
        $jobs = [];

        foreach ($sessions as $session) {
            $jobStores = [];
            $issues = collect();

            foreach ($session->trip?->stops ?? [] as $stop) {
                /** @var TripStop $stop */
                if ($f['store_ids'] !== [] && ! in_array((int) $stop->store_id, $f['store_ids'], true)) {
                    continue;
                }

                $matching = $stop->ticketIssues->filter(fn (TicketIssue $ti) => $this->matches($ti, $f))->values();
                if ($f['issue_ids'] !== [] && $matching->isEmpty()) {
                    continue;
                }

                $label = $stop->store?->store_number ?? ($stop->other_store ?: 'Other location');
                $jobStores[$label] = array_merge($jobStores[$label] ?? [], $matching->pluck('id')->all());
                $issues = $issues->merge($matching);
            }

            if ($filtered && $jobStores === []) {
                continue;
            }

            $d = $this->trips->durations($session);
            $jobs[] = [
                'technician_id' => (int) $session->technician_id,
                'sort' => $session->clock_in->copy()->utc()->format('Y-m-d\TH:i:s'),
                'trip_id' => (int) $session->trip_id,
                'trip_session_id' => (int) $session->id,
                'start' => $session->clock_in,
                'end' => $session->clock_out,
                'stores' => $jobStores,
                'issues' => $issues->unique('id')->values(),
                'hours' => [
                    'work' => round($d['work'] / 60, 2),
                    'travel' => round($d['travel'] / 60, 2),
                    'parts_run' => round($d['parts_run'] / 60, 2),
                    'break' => round($d['break'] / 60, 2),
                ],
                'paid' => $session->paymentLines->isNotEmpty(),
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

            foreach ($own->sortByDesc('sort') as $job) {
                $h = $job['hours'];
                $paidHours = round($h['work'] + $h['travel'] + $h['parts_run'], 2);
                foreach ($h as $k => $v) {
                    $hours[$k] = round($hours[$k] + $v, 2);
                }
                $lastVisitAt ??= $job['start'];

                foreach ($job['stores'] as $storeKey => $issueIds) {
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
                    'source' => 'session',
                    'trip_id' => $job['trip_id'],
                    'trip_session_id' => $job['trip_session_id'],
                    'start' => $job['start'],
                    'end' => $job['end'],
                    'stores' => array_map('strval', array_keys($job['stores'])),
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
        if ($f['store_ids'] !== [] && ! in_array((int) $ti->ticket->store_id, $f['store_ids'], true)) {
            return false;
        }

        return $f['issue_ids'] === [] || in_array((int) $ti->issue_id, $f['issue_ids'], true);
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

    /** With a store or issue filter, money is read from the matching payment lines only. */
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
     * Money per payment line type, every type present.
     *
     * @return array<string, string>
     */
    private function noKinds(): array
    {
        return array_fill_keys(PaymentLineType::values(), '0.00');
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
