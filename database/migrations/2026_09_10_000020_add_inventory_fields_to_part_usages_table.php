<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DDL only — the backfill is a separate migration on purpose. MySQL DDL is
     * not transactional, so a combined file that failed halfway would leave the
     * columns added with no backfill and no way to tell.
     *
     * Every column has a default rather than being made NOT NULL after the
     * fact, so code that only knows how to write {part_id, cost} keeps
     * producing valid rows for the whole rollout.
     */
    public function up(): void
    {
        Schema::table('part_usages', function (Blueprint $table) {
            // `cost` is unchanged and stays the authoritative GROSS total
            // outlay (quantity * unit_cost). TicketService's part_cost_single_gt
            // and part_cost_total_gt filters sum it; making it net-of-returns
            // would silently change what those filters mean.
            $table->decimal('quantity', 10, 2)->default(1)->after('part_id');
            $table->decimal('unit_cost', 10, 4)->default(0)->after('quantity');

            // Where it came from, and who actually paid.
            $table->string('source')->default('purchased')->index()->after('cost');
            $table->string('paid_by')->default('us')->index()->after('source');
            $table->foreignId('paid_by_technician_id')->nullable()->constrained('technicians')->restrictOnDelete()->after('paid_by');
            $table->foreignId('storage_location_id')->nullable()->constrained('storage_locations')->restrictOnDelete()->after('paid_by_technician_id');

            // Bought for the job, not all used, remainder handed back to a shelf.
            $table->decimal('returned_quantity', 10, 2)->default(0)->after('storage_location_id');
            $table->foreignId('returned_to_storage_location_id')->nullable()->constrained('storage_locations')->restrictOnDelete()->after('returned_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('part_usages', function (Blueprint $table) {
            // The indexes have to go before the columns they cover, or SQLite
            // refuses the drop and MySQL leaves a dangling index behind.
            $table->dropIndex('part_usages_source_index');
            $table->dropIndex('part_usages_paid_by_index');
        });

        Schema::table('part_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by_technician_id');
            $table->dropConstrainedForeignId('storage_location_id');
            $table->dropConstrainedForeignId('returned_to_storage_location_id');
            $table->dropColumn(['quantity', 'unit_cost', 'source', 'paid_by', 'returned_quantity']);
        });
    }
};
