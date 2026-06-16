<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_pay_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->decimal('total_working_hours', 8, 2)->nullable();
            $table->decimal('gas', 10, 2)->nullable();
            $table->decimal('invoices', 10, 2)->nullable();
            $table->decimal('hourly_payment_rate', 10, 4)->nullable();
            $table->decimal('money_owed', 10, 2)->nullable();
            $table->decimal('travel_time', 8, 2)->nullable();
            $table->decimal('total_break_time', 8, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_lines');
    }
};
