<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tickets.store_id has been nullable since 2026_06_26_200455, with a
     * free-text other_store for locations that are not in the replicated store
     * list. Pay lines had no such escape hatch, so a payment covering an
     * off-system location could not produce a line at all. This mirrors what
     * that migration did to tickets.
     */
    public function up(): void
    {
        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->string('other_store')->nullable()->after('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->dropColumn('other_store');
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
        });
    }
};
