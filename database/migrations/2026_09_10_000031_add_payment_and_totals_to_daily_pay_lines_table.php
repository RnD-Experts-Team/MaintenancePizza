<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DDL only; the backfill that fills daily_pay_payment_id in is the next
     * migration. The column stays nullable until every existing line has been
     * pointed at a payment.
     */
    public function up(): void
    {
        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->foreignId('daily_pay_payment_id')->nullable()->constrained('daily_pay_payments')->cascadeOnDelete()->after('daily_pay_entry_id');

            // Per-store money, alongside the payment-level fields of the same
            // name. A line may carry its own lump sum when one store's work was
            // agreed as a flat amount.
            $table->decimal('lump_sum', 10, 2)->nullable();
            $table->decimal('parts_run_time', 8, 2)->nullable();
            $table->decimal('line_total', 10, 2)->nullable();

            // The gathered figures attributed to this store.
            $table->decimal('frozen_work_hours', 8, 2)->nullable();
            $table->decimal('frozen_travel_hours', 8, 2)->nullable();
            $table->decimal('frozen_break_hours', 8, 2)->nullable();
            $table->decimal('frozen_parts_run_hours', 8, 2)->nullable();
            $table->decimal('frozen_reimbursable_parts', 10, 2)->nullable();

            // Set when the payload states the hours explicitly. recalculate()
            // refreshes gathered lines and leaves overridden ones alone —
            // without this, re-gathering would silently wipe a hand correction.
            $table->boolean('hours_overridden')->default(false);
        });

        // technician_id is deliberately KEPT on the line even though the
        // payment now owns the payee. It is denormalised (always equal to
        // payment.technician_id) purely so DailyPayEntryService::list()'s
        // technician filter and DailyPayLinesSheet keep working untouched.
    }

    public function down(): void
    {
        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('daily_pay_payment_id');
            $table->dropColumn([
                'lump_sum',
                'parts_run_time',
                'line_total',
                'frozen_work_hours',
                'frozen_travel_hours',
                'frozen_break_hours',
                'frozen_parts_run_hours',
                'frozen_reimbursable_parts',
                'hours_overridden',
            ]);
        });
    }
};
