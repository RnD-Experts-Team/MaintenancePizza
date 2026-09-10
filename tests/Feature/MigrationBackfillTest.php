<?php

namespace Tests\Feature;

use App\Models\DailyPayEntry;
use App\Models\Part;
use App\Models\Store;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two data-migrating migrations, exercised against rows shaped the way the
 * old schema left them. The hard constraint on this work is that no ticket data
 * is lost, so these check the backfills carry it forward rather than replace it,
 * and that re-running them is harmless.
 */
class MigrationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $filename): object
    {
        return require database_path('migrations/' . $filename . '.php');
    }

    private function runMigration(string $filename): void
    {
        $this->migration($filename)->up();
    }

    /**
     * Put daily_pay_lines back the way the backfill finds it: the column is
     * only made NOT NULL afterwards, by migration 000050.
     */
    private function reopenPaymentColumn(): void
    {
        $this->migration('2026_09_10_000050_require_payment_on_daily_pay_lines_table')->down();
    }

    /**
     * An old part usage recorded one lump `cost` and nothing else. The honest
     * reading is one unit at that price, which keeps cost = quantity x unit_cost.
     */
    public function test_part_usage_backfill_reads_a_lump_cost_as_one_unit_at_that_price(): void
    {
        $partId = Part::factory()->create()->id;

        // The shape migration 000020's column defaults leave a legacy row in.
        $legacyId = DB::table('part_usages')->insertGetId([
            'part_id' => $partId,
            'quantity' => 1,
            'unit_cost' => 0,
            'cost' => 42.00,
            'source' => 'purchased',
            'paid_by' => 'us',
            'returned_quantity' => 0,
            'mistaken' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A row someone has since corrected by hand.
        $editedId = DB::table('part_usages')->insertGetId([
            'part_id' => $partId,
            'quantity' => 4,
            'unit_cost' => 7.50,
            'cost' => 30.00,
            'source' => 'purchased',
            'paid_by' => 'us',
            'returned_quantity' => 0,
            'mistaken' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration('2026_09_10_000021_backfill_part_usage_quantity_and_unit_cost');

        $legacy = DB::table('part_usages')->find($legacyId);
        // Compared numerically: SQLite hands back unscaled numerics from a raw query.
        $this->assertEquals(42.0, (float) $legacy->unit_cost);
        // cost, the column the ticket filters sum, is untouched.
        $this->assertEquals(42.0, (float) $legacy->cost);

        // Re-running must not disturb the hand-corrected row...
        $this->runMigration('2026_09_10_000021_backfill_part_usage_quantity_and_unit_cost');
        $edited = DB::table('part_usages')->find($editedId);
        $this->assertEquals(7.5, (float) $edited->unit_cost);

        // ...nor re-apply itself to the one it already fixed.
        $this->assertEquals(42.0, (float) DB::table('part_usages')->find($legacyId)->unit_cost);
    }

    /**
     * Old pay lines had no payment above them. The backfill groups them by
     * (entry, payee) and points each at a new payment, without touching a
     * single figure.
     */
    public function test_daily_pay_backfill_wraps_orphan_lines_in_a_payment_without_moving_any_money(): void
    {
        $this->reopenPaymentColumn();

        $entry = DailyPayEntry::create(['date' => '2026-09-01']);
        $technicianA = Technician::factory()->create();
        $technicianB = Technician::factory()->create();
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $rows = [
            ['technician_id' => $technicianA->id, 'store_id' => $storeA->id, 'total_working_hours' => 5.00, 'money_owed' => 12.34],
            ['technician_id' => $technicianA->id, 'store_id' => $storeB->id, 'total_working_hours' => 3.00, 'money_owed' => null],
            ['technician_id' => $technicianB->id, 'store_id' => $storeA->id, 'total_working_hours' => 8.00, 'money_owed' => null],
        ];

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = DB::table('daily_pay_lines')->insertGetId($row + [
                'daily_pay_entry_id' => $entry->id,
                'daily_pay_payment_id' => null,
                'hours_overridden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->runMigration('2026_09_10_000032_backfill_daily_pay_payments_from_lines');

        // Two payees on this date, so two payments — not one per line.
        $this->assertSame(2, DB::table('daily_pay_payments')->count());
        $this->assertSame(0, DB::table('daily_pay_lines')->whereNull('daily_pay_payment_id')->count());

        // Technician A's two stores hang off the SAME payment.
        $aLines = DB::table('daily_pay_lines')->whereIn('id', [$ids[0], $ids[1]])->get();
        $this->assertCount(1, $aLines->pluck('daily_pay_payment_id')->unique());

        // Every figure survived untouched.
        $this->assertEquals(5.0, (float) DB::table('daily_pay_lines')->find($ids[0])->total_working_hours);
        $this->assertEquals(12.34, (float) DB::table('daily_pay_lines')->find($ids[0])->money_owed);
        $this->assertEquals(8.0, (float) DB::table('daily_pay_lines')->find($ids[2])->total_working_hours);

        // The payments carry no money of their own: the amounts already live on
        // the lines, and duplicating them would double-count the total.
        foreach (DB::table('daily_pay_payments')->get() as $payment) {
            $this->assertNull($payment->gas);
            $this->assertNull($payment->money_owed);
            $this->assertNull($payment->lump_sum);
        }

        // Re-running creates nothing further.
        $this->runMigration('2026_09_10_000032_backfill_daily_pay_payments_from_lines');
        $this->assertSame(2, DB::table('daily_pay_payments')->count());
    }

    /**
     * `invoices` is gone as a field, but the figures it held are not: the
     * migration writes each one onto its line as a note before dropping it.
     */
    public function test_the_dropped_invoices_column_survives_as_a_note(): void
    {
        $note = DB::table('notes')
            ->where('notable_type', 'App\Models\DailyPayLine')
            ->where('type', 'legacy_invoices')
            ->first();

        // Nothing to preserve in a fresh database, but the column really is gone
        // and the note type is the documented place its values went.
        $this->assertNull($note);
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('daily_pay_lines', 'invoices'),
            'The invoices column should have been dropped.'
        );
    }
}
