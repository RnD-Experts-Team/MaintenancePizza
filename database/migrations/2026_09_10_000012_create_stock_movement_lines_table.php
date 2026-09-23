<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->restrictOnDelete();
            // Always a positive magnitude. Which way it moves is `direction`.
            $table->decimal('quantity', 12, 2);
            // +1 in, -1 out. Denormalised from the movement type on purpose: it
            // makes the whole ledger a single SUM(quantity * direction), and it
            // lets one movement carry both directions — a transfer is a -1 line
            // at the source and a +1 line at the destination.
            $table->tinyInteger('direction');
            $table->decimal('unit_cost', 10, 4)->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->timestamps();

            // Deliberately NOT unique on (movement, part, location): the same
            // part can legitimately appear twice in one batch at two prices.
            $table->index(['part_id', 'storage_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_lines');
    }
};
