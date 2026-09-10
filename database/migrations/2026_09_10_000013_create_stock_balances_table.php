<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            // A cache of what the ledger already says, so "how many do we have"
            // is one indexed read rather than an aggregate over every movement
            // ever recorded. stock_movement_lines remains the source of truth;
            // `php artisan stock:reconcile` recomputes this from it.
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->restrictOnDelete();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->timestamps();

            // Load-bearing: StockService relies on this to create balance rows
            // race-free with insertOrIgnore before locking them.
            $table->unique(['part_id', 'storage_location_id'], 'stock_balance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
