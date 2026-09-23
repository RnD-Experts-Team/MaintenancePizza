<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_payment_attendance_entry', function (Blueprint $table) {
            // Which attendance entries a payment has already counted, and how
            // many minutes of each it took. This IS the anti-double-count
            // mechanism: an entry linked to five of the payment's issues across
            // three of its stores can still only be claimed once, and that is
            // enforced by the unique index below rather than by careful PHP.
            $table->id();
            $table->foreignId('daily_pay_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_entry_id')->constrained('attendance_entries')->cascadeOnDelete();
            // Which store's line it was attributed to. Null when the work could
            // not be pinned to one store — it still counts at payment level.
            $table->foreignId('daily_pay_line_id')->nullable()->constrained('daily_pay_lines')->cascadeOnDelete();

            $table->unsignedInteger('work_minutes')->default(0);
            $table->unsignedInteger('travel_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('parts_run_minutes')->default(0);
            $table->timestamps();

            $table->unique(['daily_pay_payment_id', 'attendance_entry_id'], 'dppay_att_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_payment_attendance_entry');
    }
};
