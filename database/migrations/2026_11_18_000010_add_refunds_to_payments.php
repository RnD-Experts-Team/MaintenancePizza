<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds (docs/MAINTENANCE.md §4): money that came back for a payment.
 *
 * A refund is a payment row of its own, `kind = refund`, pointing at the
 * payment it refunds (`refund_of_payment_id`) and dated when the money came
 * back (`paid_at`). Its lines are negative and each points at the line it
 * gives money back for (`refund_of_line_id`), with that line's type, place and
 * tickets, so every total, analytics figure and report that adds up payments
 * nets the refund in the month it happened, at the right store and type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('kind', 16)->default('payment')->after('id');
            $table->foreignId('refund_of_payment_id')->nullable()->after('kind')
                ->constrained('payments')->restrictOnDelete();
        });

        Schema::table('payment_lines', function (Blueprint $table) {
            $table->foreignId('refund_of_line_id')->nullable()->after('payment_id')
                ->constrained('payment_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_line_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_payment_id');
            $table->dropColumn('kind');
        });
    }
};
