<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing line a payment to hang off, grouping by
     * (entry, technician) — which is exactly what a payment now means: one
     * person, one date, however many stores.
     *
     * No daily pay row is dropped or rewritten; only a nullable pointer is
     * filled in. Idempotent, because both the scan and the update are guarded
     * on daily_pay_payment_id still being null.
     */
    public function up(): void
    {
        DB::table('daily_pay_lines')
            ->select('daily_pay_entry_id', 'technician_id')
            ->whereNull('daily_pay_payment_id')
            ->distinct()
            ->orderBy('daily_pay_entry_id')
            ->orderBy('technician_id')
            ->chunk(500, function ($groups) {
                foreach ($groups as $group) {
                    $paymentId = DB::table('daily_pay_payments')->insertGetId([
                        'daily_pay_entry_id' => $group->daily_pay_entry_id,
                        'technician_id' => $group->technician_id,
                        // Every money column is left NULL on purpose. The
                        // historical amounts already live on the lines, and
                        // writing them here as well would double-count them
                        // into the payment total.
                        'created_by' => DB::table('daily_pay_entries')
                            ->where('id', $group->daily_pay_entry_id)
                            ->value('created_by'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('daily_pay_lines')
                        ->where('daily_pay_entry_id', $group->daily_pay_entry_id)
                        ->where('technician_id', $group->technician_id)
                        ->whereNull('daily_pay_payment_id')
                        ->update(['daily_pay_payment_id' => $paymentId]);
                }
            });
    }

    /**
     * Safe to reverse: at this point payments carry no data of their own, so
     * unhooking and clearing them loses nothing.
     */
    public function down(): void
    {
        DB::table('daily_pay_lines')->update(['daily_pay_payment_id' => null]);
        DB::table('daily_pay_payments')->delete();
    }
};
