<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every line now belongs to a payment, so make the database say so.
     *
     * This is the one migration that alters an existing column, which rebuilds
     * the table on MySQL — run it in a quiet window. It refuses to run at all
     * if anything is still unassigned, rather than silently failing on the NOT
     * NULL constraint halfway through.
     */
    public function up(): void
    {
        $orphans = DB::table('daily_pay_lines')->whereNull('daily_pay_payment_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "{$orphans} daily pay line(s) still have no payment. Run the "
                . '2026_09_10_000032 backfill first, then re-run this migration.'
            );
        }

        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->foreignId('daily_pay_payment_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->foreignId('daily_pay_payment_id')->nullable()->change();
        });
    }
};
