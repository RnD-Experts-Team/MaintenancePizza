<?php

namespace App\Services;

use App\Enums\PaymentLineType;
use App\Enums\StockMovementType;
use App\Models\MaintenanceSetting;
use App\Models\TripSession;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/maintenance-analytics/finances (docs/MAINTENANCE.md §6):
 * where the money went in a range of days.
 *
 * Everything is summed from PAYMENT LINES, never from payment totals: a
 * payment can split its money across stores, and the store filter has to cut
 * inside it. Mistaken payments are left out everywhere.
 *
 * Filters, all optional and combined with AND:
 *   stores       lines at those stores (other-store and storage lines drop out)
 *   category_id  lines of payments to TECHNICIANS of that category; supplier
 *                payments drop out (a supplier has no category)
 *   technician_ids / supplier_id  lines of payments to those technicians / that supplier
 *
 * A line linked to several ticket issues is shared evenly between them in the
 * ticket figures, so no money is counted twice.
 *
 * Stock is global (storage is not store-scoped), so the stock block ignores
 * the payment filters; it is valued from the ledger at each part's average
 * cost (the weighted cost of everything that came in with a price).
 *
 * Every figure is one aggregate query; PHP only buckets the per-day trend
 * rows into weeks or months and formats the money.
 */
class FinanceAnalyticsService
{
    /** Up to this many days the trend is by ISO week; longer, by month. */
    public const WEEKLY_UP_TO_DAYS = 92;

    /** How many rows the long ticket lists keep. */
    public const TOP = 10;

    public const ESTIMATE_ROWS = 50;

    /** Mirrors the default in docs §2 for when maintenance_settings has none. */
    public const DEFAULT_APPROVAL_LEVELS = [
        ['max' => 300, 'level' => 'specialist', 'label' => 'Maintenance Specialist'],
        ['max' => 1000, 'level' => 'manager', 'label' => 'Maintenance Manager'],
        ['max' => null, 'level' => 'management', 'label' => 'Management'],
    ];

    public function __construct(private TripService $trips)
    {
    }

    /**
     * @param  Carbon  $from  first day, included
     * @param  Carbon  $to  last day, included
     * @param  array<int, int>|null  $storeIds  null = every store
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to, ?array $storeIds, ?int $categoryId, ?array $technicianIds, ?int $supplierId): array
    {
        $filters = compact('storeIds', 'categoryId', 'technicianIds', 'supplierId');
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $totals = $this->lines($from, $to, $filters)
            ->selectRaw('COALESCE(SUM(pl.amount), 0) as spent, COUNT(DISTINCT pl.payment_id) as payments, COUNT(*) as line_count')
            ->first();
        $previous = (float) $this->lines($prevFrom, $prevTo, $filters)->sum('pl.amount');
        $spent = (float) $totals->spent;

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
                'trend_by' => $days > self::WEEKLY_UP_TO_DAYS ? 'month' : 'week',
            ],
            'totals' => [
                'spent' => $this->money($spent),
                'previous_spent' => $this->money($previous),
                // Null when there is nothing to compare with: "+∞ %" helps nobody.
                'change_pct' => round($previous, 2) == 0.0 ? null : round(($spent - $previous) / $previous * 100, 1),
                'payments' => (int) $totals->payments,
                'lines' => (int) $totals->line_count,
            ],
            'by_type' => $this->byType($from, $to, $filters),
            'trend' => $this->trend($from, $to, $days, $filters),
            'by_store' => $this->byStore($from, $to, $filters),
            'by_technician' => $this->byTechnician($from, $to, $filters),
            'by_supplier' => $this->bySupplier($from, $to, $filters),
            'by_category' => $this->byCategory($from, $to, $filters),
            'stock' => $this->stock($from, $to),
            'tickets' => $this->tickets($from, $to, $filters),
            'unpaid' => $this->unpaid($filters),
        ];
    }

    // ---------------------------------------------------------------- Lines

    /**
     * The payment lines in a range of days, filtered.
     *
     * paid_at is compared as `>= first day` and `< the day after the last`:
     * right whether the column holds "2026-10-05" or "2026-10-05 00:00:00".
     *
     * @param  array{storeIds: array<int,int>|null, categoryId: int|null, technicianIds: array<int,int>|null, supplierId: int|null}  $f
     */
    private function lines(Carbon $from, Carbon $to, array $f): Builder
    {
        return DB::table('payment_lines as pl')
            ->join('payments as p', 'p.id', '=', 'pl.payment_id')
            ->where('p.mistaken', false)
            ->where('p.paid_at', '>=', $from->toDateString())
            ->where('p.paid_at', '<', $to->copy()->addDay()->toDateString())
            ->when($f['storeIds'] !== null, fn (Builder $q) => $q->whereIn('pl.store_id', $f['storeIds']))
            ->when($f['technicianIds'] !== null, fn (Builder $q) => $q->whereIn('p.technician_id', $f['technicianIds']))
            ->when($f['supplierId'] !== null, fn (Builder $q) => $q->where('p.supplier_id', $f['supplierId']))
            ->when($f['categoryId'] !== null, fn (Builder $q) => $q->whereIn(
                'p.technician_id',
                DB::table('technicians')->select('id')->where('category_id', $f['categoryId']),
            ));
    }

    /** @return list<array<string, mixed>> */
    private function byType(Carbon $from, Carbon $to, array $f): array
    {
        $sums = $this->lines($from, $to, $f)
            ->groupBy('pl.type')
            ->selectRaw('pl.type, SUM(pl.amount) as amount')
            ->pluck('amount', 'type');

        // Every type, in the enum's order, so the chart's legend never shifts.
        return array_map(fn (PaymentLineType $t) => [
            'type' => $t->value,
            'label' => $t->label(),
            'amount' => $this->money($sums[$t->value] ?? 0),
        ], PaymentLineType::cases());
    }

    /**
     * Per ISO week (Monday's date) or per month (YYYY-MM), every period of the
     * range present even when nothing was paid in it.
     *
     * @return list<array<string, mixed>>
     */
    private function trend(Carbon $from, Carbon $to, int $days, array $f): array
    {
        $monthly = $days > self::WEEKLY_UP_TO_DAYS;
        $key = fn (Carbon $d) => $monthly ? $d->format('Y-m') : $d->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

        $periods = [];
        for ($d = $from->copy(); $d->lte($to); $monthly ? $d->addMonthNoOverflow()->startOfMonth() : $d->addWeek()->startOfWeek(Carbon::MONDAY)) {
            $periods[$key($d)] = array_fill_keys(PaymentLineType::values(), 0.0);
        }

        // One row per (day, type), summed by the database.
        $rows = $this->lines($from, $to, $f)
            ->groupBy(DB::raw('DATE(p.paid_at)'), 'pl.type')
            ->selectRaw('DATE(p.paid_at) as day, pl.type, SUM(pl.amount) as amount')
            ->get();

        foreach ($rows as $row) {
            $period = $key(Carbon::parse($row->day));
            if (isset($periods[$period][$row->type])) {
                $periods[$period][$row->type] += (float) $row->amount;
            }
        }

        $out = [];
        foreach ($periods as $period => $types) {
            $out[] = [
                'period' => $period,
                'amount' => $this->money(array_sum($types)),
                'by_type' => array_map(fn ($v) => $this->money($v), $types),
            ];
        }

        return $out;
    }

    /**
     * By store number; lines for a store outside the list are "other" and
     * parts bought for the shelf are "storage", so the rows add up to spent.
     *
     * @return list<array<string, mixed>>
     */
    private function byStore(Carbon $from, Carbon $to, array $f): array
    {
        $place = "CASE WHEN pl.store_id IS NOT NULL THEN s.store_number WHEN pl.storage_location_id IS NOT NULL THEN 'storage' ELSE 'other' END";

        return $this->lines($from, $to, $f)
            ->leftJoin('stores as s', 's.id', '=', 'pl.store_id')
            ->groupBy(DB::raw($place))
            ->selectRaw("{$place} as store_number, SUM(pl.amount) as amount")
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => ['store_number' => $r->store_number, 'amount' => $this->money($r->amount)])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function byTechnician(Carbon $from, Carbon $to, array $f): array
    {
        return $this->lines($from, $to, $f)
            ->join('technicians as t', 't.id', '=', 'p.technician_id')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->groupBy('t.id', 't.name', 'c.id', 'c.name')
            ->selectRaw('t.id, t.name, c.id as category_id, c.name as category_name, SUM(pl.amount) as amount')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'category' => $r->category_id === null ? null : ['id' => (int) $r->category_id, 'name' => $r->category_name],
                'amount' => $this->money($r->amount),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function bySupplier(Carbon $from, Carbon $to, array $f): array
    {
        return $this->lines($from, $to, $f)
            ->join('suppliers as sp', 'sp.id', '=', 'p.supplier_id')
            ->groupBy('sp.id', 'sp.name')
            ->selectRaw('sp.id, sp.name, SUM(pl.amount) as amount')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'amount' => $this->money($r->amount)])
            ->all();
    }

    /**
     * Technician categories (id null = technicians with no category).
     * Supplier payments are not here: a supplier has no category -- they are
     * in by_supplier.
     *
     * @return list<array<string, mixed>>
     */
    private function byCategory(Carbon $from, Carbon $to, array $f): array
    {
        return $this->lines($from, $to, $f)
            ->join('technicians as t', 't.id', '=', 'p.technician_id')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.id, c.name, SUM(pl.amount) as amount')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id === null ? null : (int) $r->id,
                'name' => $r->name ?? 'No category',
                'amount' => $this->money($r->amount),
            ])
            ->all();
    }

    // ---------------------------------------------------------------- Stock

    /**
     * Each part's average cost: the quantity-weighted unit cost of every line
     * that brought it in with a price. Undone movements and the mistaken ones
     * they undid are left out -- together they are nothing.
     */
    private function averageCosts(): Builder
    {
        return DB::table('stock_movement_lines as al')
            ->join('stock_movements as am', 'am.id', '=', 'al.stock_movement_id')
            ->where('al.direction', 1)
            ->whereNotNull('al.unit_cost')
            ->where('al.quantity', '>', 0)
            ->where('am.mistaken', false)
            ->where('am.type', '!=', StockMovementType::Undone->value)
            ->groupBy('al.part_id')
            ->selectRaw('al.part_id, SUM(al.quantity * al.unit_cost) * 1.0 / SUM(al.quantity) as cost');
    }

    /** @return array<string, string> */
    private function stock(Carbon $from, Carbon $to): array
    {
        // What is on the shelves now (positive balances only: a negative
        // balance is a correction waiting to happen, not money owed to us).
        $onHand = DB::table('stock_balances as b')
            ->leftJoinSub($this->averageCosts(), 'a', 'a.part_id', '=', 'b.part_id')
            ->where('b.quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(b.quantity * COALESCE(a.cost, 0)), 0) as value')
            ->value('value');

        // What moved in the range, a line at its own cost when it has one.
        $moved = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoinSub($this->averageCosts(), 'a', 'a.part_id', '=', 'l.part_id')
            ->where('m.mistaken', false)
            ->where('m.moved_at', '>=', $from->toDateString())
            ->where('m.moved_at', '<', $to->copy()->addDay()->toDateString())
            ->whereIn('m.type', [
                StockMovementType::Bought->value,
                StockMovementType::Used->value,
                StockMovementType::StockCount->value,
                StockMovementType::DamagedLost->value,
            ])
            ->groupBy('m.type', 'l.direction')
            ->selectRaw('m.type, l.direction, SUM(l.quantity * COALESCE(l.unit_cost, a.cost, 0)) as value')
            ->get();

        $value = fn (string $type, ?int $direction = null) => $moved
            ->filter(fn ($r) => $r->type === $type && ($direction === null || (int) $r->direction === $direction))
            ->sum(fn ($r) => (float) $r->value);

        return [
            'on_hand_value' => $this->money($onHand),
            'bought_value' => $this->money($value(StockMovementType::Bought->value)),
            'used_value' => $this->money($value(StockMovementType::Used->value)),
            'count_losses' => $this->money($value(StockMovementType::StockCount->value, -1)),
            'count_gains' => $this->money($value(StockMovementType::StockCount->value, 1)),
            'damaged_lost_value' => $this->money($value(StockMovementType::DamagedLost->value)),
        ];
    }

    // -------------------------------------------------------------- Tickets

    /**
     * Lines linked to ticket issues, one row per (line, issue), with the
     * line's amount shared evenly between its issues (`share`).
     */
    private function issueShares(Carbon $from, Carbon $to, array $f): Builder
    {
        $counts = DB::table('payment_line_ticket_issue')
            ->groupBy('payment_line_id')
            ->selectRaw('payment_line_id, COUNT(*) as n');

        return $this->lines($from, $to, $f)
            ->join('payment_line_ticket_issue as plti', 'plti.payment_line_id', '=', 'pl.id')
            ->joinSub($counts, 'cnt', 'cnt.payment_line_id', '=', 'pl.id')
            ->join('ticket_issues as ti', 'ti.id', '=', 'plti.ticket_issue_id')
            ->join('tickets as tk', 'tk.id', '=', 'ti.ticket_id');
    }

    private const SHARE = 'pl.amount * 1.0 / cnt.n';

    /** @return array<string, mixed> */
    private function tickets(Carbon $from, Carbon $to, array $f): array
    {
        $share = self::SHARE;

        $overall = $this->issueShares($from, $to, $f)
            ->selectRaw("COALESCE(SUM({$share}), 0) as amount, COUNT(DISTINCT tk.id) as tickets")
            ->first();

        $top = $this->issueShares($from, $to, $f)
            ->leftJoin('stores as s', 's.id', '=', 'tk.store_id')
            ->groupBy('tk.id', 's.store_number', 'tk.other_store')
            ->selectRaw("tk.id, s.store_number, tk.other_store, SUM({$share}) as amount")
            ->orderByDesc('amount')
            ->orderBy('tk.id')
            ->limit(self::TOP)
            ->get();
        $titles = $this->ticketTitles($top->pluck('id')->map(fn ($id) => (int) $id)->all());

        $byIssue = $this->issueShares($from, $to, $f)
            ->leftJoin('issues as i', 'i.id', '=', 'ti.issue_id')
            ->groupBy('ti.issue_id', 'i.title')
            ->selectRaw("ti.issue_id, i.title, SUM({$share}) as amount, COUNT(DISTINCT tk.id) as tickets")
            ->orderByDesc('amount')
            ->get();

        // The latest non-mistaken estimate of each issue, against what was
        // paid on it in the range.
        $latestEstimate = DB::table('issue_inspections')
            ->where('mistaken', false)
            ->whereNotNull('estimated_cost')
            ->groupBy('ticket_issue_id')
            ->selectRaw('ticket_issue_id, MAX(id) as id');

        $estimates = $this->issueShares($from, $to, $f)
            ->joinSub($latestEstimate, 'le', 'le.ticket_issue_id', '=', 'ti.id')
            ->join('issue_inspections as ins', 'ins.id', '=', 'le.id')
            ->leftJoin('issues as i', 'i.id', '=', 'ti.issue_id')
            ->groupBy('ti.id', 'i.title', 'ti.other_title', 'ins.estimated_cost')
            ->selectRaw("ti.id, i.title, ti.other_title, ins.estimated_cost, SUM({$share}) as actual")
            ->orderByDesc('actual')
            ->orderBy('ti.id')
            ->limit(self::ESTIMATE_ROWS)
            ->get();

        // The same catalog issue at the same store on two or more tickets.
        $recurring = $this->issueShares($from, $to, $f)
            ->join('stores as s', 's.id', '=', 'tk.store_id')
            ->join('issues as i', 'i.id', '=', 'ti.issue_id')
            ->groupBy('s.store_number', 'ti.issue_id', 'i.title')
            ->havingRaw('COUNT(DISTINCT tk.id) >= 2')
            ->selectRaw("s.store_number, ti.issue_id, i.title, COUNT(DISTINCT tk.id) as tickets, SUM({$share}) as amount")
            ->orderByDesc('amount')
            ->get();

        return [
            'cost_per_ticket_avg' => (int) $overall->tickets === 0 ? null : $this->money((float) $overall->amount / (int) $overall->tickets),
            'top_tickets' => $top->map(fn ($r) => [
                'ticket_id' => (int) $r->id,
                'store_number' => $r->store_number ?? $r->other_store,
                'title' => $titles[(int) $r->id] ?? null,
                'amount' => $this->money($r->amount),
            ])->all(),
            'by_issue' => $byIssue->map(fn ($r) => [
                'issue_id' => $r->issue_id === null ? null : (int) $r->issue_id,
                'title' => $r->title ?? 'Other',
                'amount' => $this->money($r->amount),
                'tickets' => (int) $r->tickets,
            ])->all(),
            'estimate_vs_actual' => $estimates->map(fn ($r) => [
                'ticket_issue_id' => (int) $r->id,
                'title' => $r->title ?? $r->other_title,
                'estimated' => $this->money($r->estimated_cost),
                'actual' => $this->money($r->actual),
            ])->all(),
            'approvals_by_level' => $this->approvalsByLevel($from, $to, $f['storeIds']),
            'recurring_costly' => $recurring->map(fn ($r) => [
                'store_number' => $r->store_number,
                'issue_id' => (int) $r->issue_id,
                'title' => $r->title,
                'tickets' => (int) $r->tickets,
                'amount' => $this->money($r->amount),
            ])->all(),
        ];
    }

    /**
     * A ticket's title is its first issue's: the catalog title, else the
     * typed one, plus "+ N more" when it has others.
     *
     * @param  array<int, int>  $ticketIds
     * @return array<int, string>
     */
    private function ticketTitles(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $first = DB::table('ticket_issues')
            ->whereIn('ticket_id', $ticketIds)
            ->whereNull('parent_id')
            ->groupBy('ticket_id')
            ->selectRaw('ticket_id, MIN(id) as id, COUNT(*) as n');

        return DB::table('ticket_issues as ti')
            ->joinSub($first, 'f', 'f.id', '=', 'ti.id')
            ->leftJoin('issues as i', 'i.id', '=', 'ti.issue_id')
            ->get(['f.ticket_id', 'f.n', 'i.title', 'ti.other_title'])
            ->mapWithKeys(fn ($r) => [
                (int) $r->ticket_id => ($r->title ?? $r->other_title ?? 'Ticket') . ((int) $r->n > 1 ? ' + ' . ((int) $r->n - 1) . ' more' : ''),
            ])
            ->all();
    }

    /**
     * Approved quotes decided in the range, per approval level (in the order
     * the settings list them). The payment filters other than stores do not
     * apply: an approval is not paid to anybody.
     *
     * @param  array<int, int>|null  $storeIds
     * @return list<array<string, mixed>>
     */
    private function approvalsByLevel(Carbon $from, Carbon $to, ?array $storeIds): array
    {
        $rows = DB::table('issue_approvals as a')
            ->join('ticket_issues as ti', 'ti.id', '=', 'a.ticket_issue_id')
            ->join('tickets as tk', 'tk.id', '=', 'ti.ticket_id')
            ->where('a.mistaken', false)
            ->where('a.decision', 'approved')
            ->where('a.decided_at', '>=', $from->toDateString())
            ->where('a.decided_at', '<', $to->copy()->addDay()->toDateString())
            ->when($storeIds !== null, fn (Builder $q) => $q->whereIn('tk.store_id', $storeIds))
            ->groupBy('a.level')
            ->selectRaw('a.level, COUNT(*) as n, SUM(a.amount) as amount')
            ->get()
            ->keyBy('level');

        $levels = MaintenanceSetting::get('approval_levels', self::DEFAULT_APPROVAL_LEVELS);
        $levels = is_array($levels) && $levels !== [] ? $levels : self::DEFAULT_APPROVAL_LEVELS;

        $out = [];
        foreach ($levels as $level) {
            $key = (string) ($level['level'] ?? '');
            $row = $rows->pull($key);
            $out[] = [
                'level' => $key,
                'label' => (string) ($level['label'] ?? $key),
                'count' => (int) ($row->n ?? 0),
                'amount' => $this->money($row->amount ?? 0),
            ];
        }

        // Levels no longer in the settings still had money approved.
        foreach ($rows as $key => $row) {
            $out[] = [
                'level' => (string) $key,
                'label' => ucfirst(str_replace('_', ' ', (string) $key)),
                'count' => (int) $row->n,
                'amount' => $this->money($row->amount),
            ];
        }

        return $out;
    }

    // --------------------------------------------------------------- Unpaid

    /**
     * What is owed NOW, whatever the range: closed, non-mistaken sessions no
     * live payment claims, and technician purchases not yet paid back.
     *
     * Filtered by technician / category; a supplier filter leaves nothing (a
     * supplier is never owed for clocked time). With stores, a session counts
     * when its trip visited one of them, a purchase when it was for one.
     *
     * @return array<string, mixed>
     */
    private function unpaid(array $f): array
    {
        if ($f['supplierId'] !== null) {
            return ['sessions' => 0, 'paid_minutes' => 0, 'purchases' => 0, 'purchases_total' => $this->money(0)];
        }

        $technicians = fn (Builder $q, string $column) => $q
            ->when($f['technicianIds'] !== null, fn (Builder $q) => $q->whereIn($column, $f['technicianIds']))
            ->when($f['categoryId'] !== null, fn (Builder $q) => $q->whereIn(
                $column,
                DB::table('technicians')->select('id')->where('category_id', $f['categoryId']),
            ));

        $sessions = DB::table('trip_sessions as ts')
            ->whereNotNull('ts.clock_out')
            ->where('ts.mistaken', false)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('payment_line_trip_session as plts')
                ->join('payment_lines as cpl', 'cpl.id', '=', 'plts.payment_line_id')
                ->join('payments as cp', 'cp.id', '=', 'cpl.payment_id')
                ->whereColumn('plts.trip_session_id', 'ts.id')
                ->where('cp.mistaken', false))
            ->when($f['storeIds'] !== null, fn (Builder $q) => $q->whereExists(fn (Builder $s) => $s->selectRaw('1')
                ->from('trip_stops as st')
                ->whereColumn('st.trip_id', 'ts.trip_id')
                ->where('st.mistaken', false)
                ->whereIn('st.store_id', $f['storeIds'])));
        $technicians($sessions, 'ts.technician_id');

        $sessionIds = $sessions->pluck('ts.id')->map(fn ($id) => (int) $id)->all();

        $purchases = DB::table('technician_purchases as tp')
            ->where('tp.mistaken', false)
            ->whereNull('tp.payment_line_id')
            ->when($f['storeIds'] !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereExists(fn (Builder $s) => $s->selectRaw('1')
                    ->from('ticket_issues as pti')
                    ->join('tickets as ptk', 'ptk.id', '=', 'pti.ticket_id')
                    ->whereColumn('pti.id', 'tp.ticket_issue_id')
                    ->whereIn('ptk.store_id', $f['storeIds']))
                ->orWhereExists(fn (Builder $s) => $s->selectRaw('1')
                    ->from('trip_stops as pst')
                    ->whereColumn('pst.id', 'tp.trip_stop_id')
                    ->whereIn('pst.store_id', $f['storeIds']))));
        $technicians($purchases, 'tp.technician_id');
        $purchaseTotals = $purchases->selectRaw('COUNT(*) as n, COALESCE(SUM(tp.total_cost), 0) as total')->first();

        return [
            'sessions' => count($sessionIds),
            'paid_minutes' => $this->paidMinutes($sessionIds),
            'purchases' => (int) $purchaseTotals->n,
            'purchases_total' => $this->money($purchaseTotals->total),
        ];
    }

    /**
     * Paid minutes of these sessions, worked out exactly as the trip page
     * does (TripService::durations: clocked time minus breaks). The walk is
     * over the unpaid sessions' own events, never over payments.
     *
     * @param  array<int, int>  $sessionIds
     */
    private function paidMinutes(array $sessionIds): int
    {
        $total = 0;

        foreach (array_chunk($sessionIds, 500) as $chunk) {
            TripSession::query()->with('events')->whereIn('id', $chunk)->get()
                ->each(function (TripSession $s) use (&$total) {
                    $total += $this->trips->paidMinutes($s);
                });
        }

        return $total;
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) ($value ?? 0), 2), 2, '.', '');
    }
}
