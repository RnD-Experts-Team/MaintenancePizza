<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technician coverage (owner, 2026-10-08): the stores a technician can cover,
 * where they are based, and notes about it ("north stores only on weekends").
 * Pickers on a ticket list the technicians who cover its store first.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('location')->nullable()->after('phone');
            $table->text('coverage_notes')->nullable()->after('location');
        });

        Schema::create('store_technician', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['technician_id', 'store_id']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_technician');

        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn(['location', 'coverage_notes']);
        });
    }
};
