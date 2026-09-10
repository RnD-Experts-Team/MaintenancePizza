<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_payments', function (Blueprint $table) {
            // The level between the day's entry and its per-store lines: one
            // payment to one payee, which may cover several stores at once.
            // A "company" is simply a Technician row named after the company,
            // so there is no separate payee table.
            $table->id();
            $table->foreignId('daily_pay_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();

            // Money entered at the payment level: amounts that are real but not
            // attributable to any one store. The same fields exist on the lines
            // for the parts that are. Both are counted; neither is derived from
            // the other.
            $table->decimal('hourly_payment_rate', 10, 4)->nullable();
            // A flat amount INSTEAD OF hours x rate, not on top of it.
            $table->decimal('lump_sum', 10, 2)->nullable();
            $table->decimal('gas', 10, 2)->nullable();
            // NOT the total. An optional extra we happen to also owe them.
            $table->decimal('money_owed', 10, 2)->nullable();

            // Gathered from the linked attendance and part usages when the
            // payment is saved, and refreshed by POST .../recalculate. Frozen
            // rather than live so a signed-off pay sheet cannot change under
            // you when someone corrects a ticket next month.
            $table->decimal('frozen_work_hours', 8, 2)->nullable();
            $table->decimal('frozen_travel_hours', 8, 2)->nullable();
            $table->decimal('frozen_break_hours', 8, 2)->nullable();
            $table->decimal('frozen_parts_run_hours', 8, 2)->nullable();
            $table->decimal('frozen_reimbursable_parts', 10, 2)->nullable();
            $table->dateTime('aggregated_at')->nullable();
            $table->foreignId('aggregated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('aggregation_warnings')->nullable();

            $table->decimal('lines_total', 10, 2)->nullable();
            // The payable figure.
            $table->decimal('total_amount', 10, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Deliberately an index, NOT unique. A unique (entry, technician)
            // looks right, but the full-replace edit path and any legitimate
            // "two separate payments to one person on one date" case would then
            // fail at the DB layer with an opaque error. One-payment-per-payee
            // is enforced in StoreDailyPayEntryRequest instead, where the
            // message is useful and an exception can be made later.
            $table->index(['daily_pay_entry_id', 'technician_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_payments');
    }
};
