<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaymentHistory\PaymentHistoryImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Load the reviewed payments history plan (see PaymentHistoryImport).
 *
 *   php artisan maintenance:import-payment-history plan.json --user=12 --last-touchable=347 --dry-run
 *
 * --last-touchable must repeat the plan's last_touchable_payment_id: whoever
 * runs it says out loud which payments it may change. Without --dry-run and
 * with every check passing, the run is committed; otherwise nothing is kept.
 * Exit code 1 when anything failed or a check did not pass.
 */
class ImportPaymentHistory extends Command
{
    protected $signature = 'maintenance:import-payment-history
        {plan : Path to the import plan (JSON)}
        {--user= : Id of the user the payments are recorded as created by}
        {--last-touchable= : The last payment id the run may change (must match the plan)}
        {--dry-run : Do everything, report, then roll it all back}
        {--report= : Write what happened, operation by operation, to this JSON file}';

    protected $description = 'Load the reviewed R&M payments history into the payments system';

    public function handle(PaymentHistoryImport $import): int
    {
        $path = (string) $this->argument('plan');
        if (! is_file($path)) {
            $this->error("No plan at {$path}.");

            return self::FAILURE;
        }

        $plan = json_decode((string) file_get_contents($path), true);
        if (! is_array($plan)) {
            $this->error('The plan is not valid JSON.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('last-touchable');
        if ($limit < 1 || $limit !== (int) ($plan['last_touchable_payment_id'] ?? 0)) {
            $this->error('--last-touchable must be given and match the plan\'s last_touchable_payment_id ('.($plan['last_touchable_payment_id'] ?? 'missing').').');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $this->option('user'));
        if ($user === null) {
            $this->error('--user must name an existing user: the payments are recorded as created by them.');

            return self::FAILURE;
        }
        Auth::setUser($user);

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? 'DRY RUN: ' : '').'importing '.count($plan['operations'] ?? []).' operations; payments after #'.$limit.' are read only.');

        try {
            $result = $import->run($plan, $dryRun);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['What', 'How many'], collect($result['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        $this->line("Money in new payments: {$result['created_total']}; refunds recorded: {$result['refunded_total']}".($result['counts']['held'] > 0 ? " (held back: {$result['held_total']})" : ''));

        $problems = collect($result['operations'])->whereIn('status', ['error', 'held']);
        foreach ($problems->take(100) as $p) {
            $rows = $p['sheet_rows'] === [] ? '' : ' sheet row(s) '.implode(', ', $p['sheet_rows']);
            $ids = $p['payment_ids'] === [] ? '' : ' payment #'.implode(', #', $p['payment_ids']);
            $this->line(strtoupper($p['status'])." [{$p['op']}]{$rows}{$ids}: {$p['message']}");
        }
        if ($problems->count() > 100) {
            $this->line('... and '.($problems->count() - 100).' more (see --report).');
        }

        $this->newLine();
        foreach ($result['checks'] as $check) {
            $this->line(($check['ok'] ? '<info>PASS</info> ' : '<error>FAIL</error> ').$check['name'].': '.$check['detail']);
        }

        if ($report = $this->option('report')) {
            file_put_contents((string) $report, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line("Report written to {$report}.");
        }

        $this->newLine();
        if ($result['committed']) {
            $this->info('Committed.');

            return self::SUCCESS;
        }

        if ($dryRun && $result['counts']['errors'] === 0 && $result['counts']['held'] === 0 && collect($result['checks'])->every(fn ($c) => $c['ok'])) {
            $this->info('Dry run clean: nothing was kept. Run again without --dry-run to commit.');

            return self::SUCCESS;
        }

        $this->error('Nothing was kept: fix the errors / failed checks above and run again.');

        return self::FAILURE;
    }
}
