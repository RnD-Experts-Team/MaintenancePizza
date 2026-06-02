<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            // Attendance (on-site) pay, per hour.
            $table->decimal('base_pay', 10, 2)->nullable();
            $table->decimal('performance_pay', 10, 2)->nullable();
            // Driving: time + distance + a separate driving pay rate.
            $table->decimal('driving_time', 8, 2)->nullable();
            $table->decimal('miles_driven', 8, 2)->nullable();
            $table->decimal('per_mile_rate', 8, 4)->nullable();
            $table->decimal('driving_base_pay', 10, 2)->nullable();
            $table->decimal('driving_performance_pay', 10, 2)->nullable();
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_entries');
    }
};
