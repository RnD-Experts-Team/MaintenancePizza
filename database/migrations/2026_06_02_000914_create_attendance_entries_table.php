<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            // Intentionally unconstrained: the dispatcher may set any subset, repeatedly.
            $table->dateTime('start_clock')->nullable();
            $table->dateTime('end_clock')->nullable();
            $table->dateTime('start_break')->nullable();
            $table->dateTime('end_break')->nullable();
            $table->dateTime('start_parts_run')->nullable();
            $table->dateTime('end_parts_run')->nullable();
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_entries');
    }
};
