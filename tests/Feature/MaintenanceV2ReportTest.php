<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Models\Store;
use App\Models\Technician;
use App\Services\MigrationV2\LegacyMap;
use App\Services\MigrationV2\PayToPayments;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan maintenance:v2-report`: counts, the conversion report, and two
 * checks that fail the command (exit 1) when the books do not add up.
 */
class MaintenanceV2ReportTest extends TestCase
{
    use RefreshDatabase;

    /** One old Daily Pay payment of 2 h × 20 = 40, converted. */
    private function convertedSheet(): int
    {
        $store = Store::factory()->create();
        $technician = Technician::factory()->create();
        $sheet = DB::table('daily_pay_entries')->insertGetId(['date' => '2026-10-05', 'created_at' => now(), 'updated_at' => now()]);
        $old = DB::table('daily_pay_payments')->insertGetId([
            'daily_pay_entry_id' => $sheet, 'technician_id' => $technician->id, 'hourly_payment_rate' => 20, 'total_amount' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_pay_lines')->insert([
            'daily_pay_entry_id' => $sheet, 'daily_pay_payment_id' => $old, 'technician_id' => $technician->id,
            'store_id' => $store->id, 'total_working_hours' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(PayToPayments::class)->run();

        return (int) LegacyMap::get('daily_pay_payments', $old, 'payments');
    }

    public function test_a_clean_conversion_passes_and_lists_what_it_reported(): void
    {
        $this->convertedSheet();
        LegacyMap::report('trips', 'Attendance entry #7 had no clock-in at all', [], 'warning');
        LegacyMap::report('parts', 'Part usage #9 did not say which technician', [], 'error');
        LegacyMap::report('statuses', '3 issue(s) moved', []);

        $this->artisan('maintenance:v2-report')
            ->expectsOutputToContain('Rows per v2 table')
            ->expectsTable(['Step', 'Level', 'Entries'], [['parts', 'error', 1], ['statuses', 'info', 1], ['trips', 'warning', 1]])
            ->expectsOutputToContain('Part usage #9 did not say which technician')
            ->expectsOutputToContain('Attendance entry #7 had no clock-in at all')
            ->doesntExpectOutputToContain('3 issue(s) moved')
            ->expectsOutputToContain('Daily Pay totals match.')
            ->expectsOutputToContain('Stock balances agree with the ledger')
            ->expectsOutputToContain('All checks passed.')
            ->assertExitCode(0);
    }

    public function test_a_payment_that_no_longer_matches_its_old_sheet_fails(): void
    {
        $payment = $this->convertedSheet();
        DB::table('payments')->where('id', $payment)->update(['total' => 41]);

        $this->artisan('maintenance:v2-report')
            ->expectsOutputToContain('The converted payments total 41.00; the old sheets said 40.00.')
            ->assertExitCode(1);
    }

    public function test_an_unconverted_old_payment_fails(): void
    {
        $this->convertedSheet();
        DB::table('migration_v2_map')->where('legacy_table', 'daily_pay_payments')->delete();

        $this->artisan('maintenance:v2-report')
            ->expectsOutputToContain('1 old Daily Pay payment(s) were not converted.')
            ->assertExitCode(1);
    }

    public function test_a_balance_that_drifted_from_the_ledger_fails(): void
    {
        $part = Part::factory()->create();
        $shelf = StorageLocation::factory()->create();
        app(StockService::class)->record(['moved_at' => now(), 'type' => StockMovementType::OpeningStock], [[
            'part_id' => $part->id, 'storage_location_id' => $shelf->id, 'quantity' => '5', 'direction' => 1, 'unit_cost' => '2',
        ]]);

        $this->artisan('maintenance:v2-report')->assertExitCode(0);

        DB::table('stock_balances')->update(['quantity' => 4]);

        $this->artisan('maintenance:v2-report')
            ->expectsOutputToContain('1 stock balance(s) differ from the ledger.')
            ->expectsTable(['Part', 'Location', 'Balance', 'Ledger'], [[(string) $part->id, (string) $shelf->id, '4.00', '5.00']])
            ->assertExitCode(1);
    }
}
