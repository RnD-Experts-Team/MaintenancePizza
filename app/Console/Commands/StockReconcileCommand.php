<?php

namespace App\Console\Commands;

use App\Models\StockBalance;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks the cached stock_balances against the ledger they are a cache of, and
 * optionally rewrites them from it. The ledger is always the source of truth —
 * this only ever copies in that direction, never the reverse.
 */
class StockReconcileCommand extends Command
{
    protected $signature = 'stock:reconcile {--fix : Rewrite the cached balances from the ledger}';

    protected $description = 'Compare cached stock balances against the movement ledger, and optionally repair them';

    public function handle(StockService $stock): int
    {
        $ledger = $stock->ledgerBalances();
        $cached = StockBalance::query()
            ->get()
            ->mapWithKeys(fn (StockBalance $b) => [
                $b->part_id . ':' . $b->storage_location_id => number_format((float) $b->quantity, 2, '.', ''),
            ])
            ->all();

        $drift = [];

        foreach ($ledger + $cached as $key => $_) {
            $truth = $ledger[$key] ?? '0.00';
            $cache = $cached[$key] ?? null;

            if ($cache === null || bccomp($truth, $cache, 2) !== 0) {
                [$partId, $locationId] = array_map('intval', explode(':', $key));
                $drift[] = [$partId, $locationId, $cache ?? '(missing)', $truth];
            }
        }

        $negative = array_filter($ledger, fn (string $q) => bccomp($q, '0', 2) < 0);

        if ($negative !== []) {
            $this->warn(count($negative) . ' (part, location) pair(s) are negative in the ledger:');
            foreach ($negative as $key => $quantity) {
                $this->line("  {$key} => {$quantity}");
            }
            $this->line('A negative balance is normally the trace of a reversal applied after the stock was consumed.');
        }

        if ($drift === []) {
            $this->info('Stock balances agree with the ledger.');

            return self::SUCCESS;
        }

        $this->table(['Part', 'Location', 'Cached', 'Ledger'], $drift);

        if (! $this->option('fix')) {
            $this->warn(count($drift) . ' balance(s) drifted. Re-run with --fix to repair them from the ledger.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($drift) {
            foreach ($drift as [$partId, $locationId, $_, $truth]) {
                StockBalance::query()->updateOrCreate(
                    ['part_id' => $partId, 'storage_location_id' => $locationId],
                    ['quantity' => $truth],
                );
            }
        });

        $this->info('Repaired ' . count($drift) . ' balance(s) from the ledger.');

        return self::SUCCESS;
    }
}
