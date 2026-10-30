<?php

namespace App\Console\Commands;

use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the v2 conversion did, and whether it kept the books straight.
 *
 *   1. how many rows each v2 table holds;
 *   2. the conversion report (migration_v2_report) by step and level, with
 *      every warning and error spelled out;
 *   3. CHECK money: the converted payments of the old Daily Pay sheets add up
 *      to exactly what the old sheets said (and every old payment was
 *      converted);
 *   4. CHECK stock: for every (part, location) the ledger sum equals the
 *      cached stock_balances quantity.
 *
 * Exit code 1 when a check fails, so a deploy script can stop on it.
 */
class MaintenanceV2Report extends Command
{
    protected $signature = 'maintenance:v2-report {--limit=50 : How many warnings/errors (and mismatches) to list}';

    protected $description = 'Summarise the maintenance v2 conversion and check money and stock still add up';

    private const TABLES = [
        'suppliers', 'trips', 'trip_sessions', 'trip_events', 'trip_stops', 'trip_stop_issues', 'trip_audits',
        'issue_info_requests', 'issue_inspections', 'issue_approvals',
        'payments', 'payment_lines', 'payment_line_ticket_issue', 'payment_line_trip_session', 'payment_revisions',
        'technician_purchases', 'stock_counts', 'stock_count_lines', 'part_units', 'maintenance_settings',
        'migration_v2_map',
    ];

    public function handle(StockService $stock): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->counts();
        $this->conversionReport($limit);

        $ok = $this->checkPayments($limit);
        $ok = $this->checkStock($stock, $limit) && $ok;

        $this->newLine();
        if ($ok) {
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->error('A check failed (see above).');

        return self::FAILURE;
    }

    private function counts(): void
    {
        $this->line('<comment>Rows per v2 table</comment>');
        $this->table(['Table', 'Rows'], array_map(
            fn (string $t) => [$t, Schema::hasTable($t) ? DB::table($t)->count() : 'missing'],
            self::TABLES,
        ));
    }

    private function conversionReport(int $limit): void
    {
        $this->line('<comment>Conversion report</comment>');

        $groups = DB::table('migration_v2_report')
            ->groupBy('step', 'level')
            ->orderBy('step')->orderBy('level')
            ->selectRaw('step, level, COUNT(*) as n')
            ->get();

        if ($groups->isEmpty()) {
            $this->line('Nothing was reported.');

            return;
        }

        $this->table(['Step', 'Level', 'Entries'], $groups->map(fn ($g) => [$g->step, $g->level, (int) $g->n])->all());

        $problems = DB::table('migration_v2_report')->whereIn('level', ['warning', 'error']);
        $total = (clone $problems)->count();

        if ($total === 0) {
            return;
        }

        $this->line("<comment>Warnings and errors</comment> ({$total})");
        $this->table(
            ['#', 'Step', 'Level', 'Message'],
            (clone $problems)
                ->orderByRaw("CASE WHEN level = 'error' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'step', 'level', 'message'])
                ->map(fn ($r) => [$r->id, $r->step, $r->level, $r->message])
                ->all(),
        );

        if ($total > $limit) {
            $this->line('... and ' . ($total - $limit) . ' more (--limit to see them).');
        }
    }

    /**
     * The converted Daily Pay payments against the old ones, in one query
     * each: unconverted old payments, the two sums, the payments that differ.
     */
    private function checkPayments(int $limit): bool
    {
        $this->line('<comment>Check: Daily Pay totals</comment>');

        if (! Schema::hasTable('daily_pay_payments')) {
            $this->line('The old Daily Pay tables are gone; nothing to compare.');

            return true;
        }

        $mapped = fn () => DB::table('daily_pay_payments as d')
            ->join('migration_v2_map as m', function ($j) {
                $j->on('m.legacy_id', '=', 'd.id')
                    ->where('m.legacy_table', 'daily_pay_payments')
                    ->where('m.new_table', 'payments');
            })
            ->join('payments as p', 'p.id', '=', 'm.new_id');

        $unconverted = DB::table('daily_pay_payments as d')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('migration_v2_map as m')
                ->whereColumn('m.legacy_id', 'd.id')
                ->where('m.legacy_table', 'daily_pay_payments')
                ->where('m.new_table', 'payments'))
            ->count();

        $sums = $mapped()
            ->whereNotNull('d.total_amount')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(p.total), 0) as new_total, COALESCE(SUM(d.total_amount), 0) as old_total')
            ->first();
        $unchecked = $mapped()->whereNull('d.total_amount')->count();

        $new = $this->money($sums->new_total);
        $old = $this->money($sums->old_total);

        $this->table(['', 'Value'], [
            ['Old payments compared', (int) $sums->n],
            ['Sum of old total_amount', $old],
            ['Sum of converted payments.total', $new],
            ['Old payments with no total_amount (not comparable)', $unchecked],
            ['Old payments not converted', $unconverted],
        ]);

        $ok = true;

        if ($unconverted > 0) {
            $this->error("{$unconverted} old Daily Pay payment(s) were not converted.");
            $ok = false;
        }

        if ($new !== $old) {
            $this->error("The converted payments total {$new}; the old sheets said {$old}.");
            $ok = false;

            $this->table(
                ['Old payment', 'Old total', 'New payment', 'New total'],
                $mapped()
                    ->whereNotNull('d.total_amount')
                    ->whereRaw('ROUND(p.total, 2) <> ROUND(d.total_amount, 2)')
                    ->orderBy('d.id')
                    ->limit($limit)
                    ->get(['d.id as old_id', 'd.total_amount', 'p.id as new_id', 'p.total'])
                    ->map(fn ($r) => [$r->old_id, $this->money($r->total_amount), $r->new_id, $this->money($r->total)])
                    ->all(),
            );
        }

        if ($ok) {
            $this->info('Daily Pay totals match.');
        }

        return $ok;
    }

    private function checkStock(StockService $stock, int $limit): bool
    {
        $this->line('<comment>Check: stock ledger against balances</comment>');

        $ledger = $stock->ledgerBalances();
        $cached = DB::table('stock_balances')->get(['part_id', 'storage_location_id', 'quantity'])
            ->mapWithKeys(fn ($b) => [$b->part_id . ':' . $b->storage_location_id => $this->money($b->quantity)])
            ->all();

        $drift = [];
        foreach ($ledger + $cached as $key => $_) {
            $truth = $this->money($ledger[$key] ?? 0);
            $cache = $cached[$key] ?? null;

            // A balance row of 0 with no ledger lines is fine; anything else must agree.
            if (($cache ?? '0.00') !== $truth) {
                [$partId, $locationId] = explode(':', $key);
                $drift[] = [$partId, $locationId, $cache ?? '(missing)', $truth];
            }
        }

        if ($drift === []) {
            $this->info('Stock balances agree with the ledger (' . count($ledger + $cached) . ' part/location pairs).');

            return true;
        }

        $this->error(count($drift) . ' stock balance(s) differ from the ledger. `php artisan stock:reconcile --fix` rewrites them from it.');
        $this->table(['Part', 'Location', 'Balance', 'Ledger'], array_slice($drift, 0, $limit));

        return false;
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) ($value ?? 0), 2), 2, '.', '');
    }
}
