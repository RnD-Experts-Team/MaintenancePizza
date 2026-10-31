<?php

use App\Services\LegacyConversion\LegacyMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage: the words people use, stock counts, and serial numbers.
 *
 * Movement types are renamed to the business's vocabulary:
 *   purchase → bought        draw → used           return → returned
 *   transfer_out / transfer_in → moved             initial_count → opening_stock
 *   adjustment → stock_count reversal → undone
 * plus a new `damaged_lost` for write-offs. Only the stored value changes; the
 * ledger's quantities and directions are untouched, so every balance stays
 * exactly what it was.
 *
 * A movement can now say what caused it: the payment line that bought the
 * parts, the ticket issue they were used on, or the stock count that
 * corrected them.
 *
 * A STOCK COUNT (جرد) is a sheet: for one storage location (or one place in
 * it) it lists every part with what the system expects; somebody types what
 * is really there; the differences, with their value, are applied as one
 * stock_count movement. A draft can be saved and finished later; an applied
 * count is kept as the report.
 *
 * PART UNITS are the individual pieces of a part with `track_serials` on.
 */
return new class extends Migration {
    private const TYPE_MAP = [
        'purchase' => 'bought',
        'draw' => 'used',
        'return' => 'returned',
        'transfer_out' => 'moved',
        'transfer_in' => 'moved',
        'adjustment' => 'stock_count',
        'initial_count' => 'opening_stock',
        'reversal' => 'undone',
    ];

    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->restrictOnDelete();
            $table->foreignId('storage_place_value_id')->nullable()->constrained('storage_place_values')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->dateTime('started_at');
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('missing_value', 12, 2)->default(0);
            $table->decimal('extra_value', 12, 2)->default(0);
            $table->text('notes_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['storage_location_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->decimal('expected', 12, 2);
            $table->decimal('counted', 12, 2)->nullable();
            $table->decimal('unit_cost', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'part_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('payment_line_id')->nullable()->after('part_usage_id')->constrained('payment_lines')->nullOnDelete();
            $table->foreignId('ticket_issue_id')->nullable()->after('payment_line_id')->constrained('ticket_issues')->nullOnDelete();
            $table->foreignId('stock_count_id')->nullable()->after('ticket_issue_id')->constrained('stock_counts')->nullOnDelete();
        });

        Schema::create('part_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->string('serial_number');
            $table->string('status', 16)->default('in_stock');
            $table->foreignId('storage_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->foreignId('ticket_issue_id')->nullable()->constrained('ticket_issues')->nullOnDelete();
            $table->foreignId('payment_line_id')->nullable()->constrained('payment_lines')->nullOnDelete();
            $table->dateTime('installed_at')->nullable();
            $table->date('warranty_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['part_id', 'serial_number']);
            $table->index(['status', 'storage_location_id']);
        });

        Schema::create('stock_movement_line_part_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_line_id')->constrained('stock_movement_lines')->cascadeOnDelete();
            $table->foreignId('part_unit_id')->constrained('part_units')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['stock_movement_line_id', 'part_unit_id'], 'sml_pu_unique');
        });

        foreach (self::TYPE_MAP as $old => $new) {
            $count = DB::table('stock_movements')->where('type', $old)->update(['type' => $new]);

            if ($count > 0) {
                LegacyMap::report('stock', "{$count} stock movement(s) renamed from \"{$old}\" to \"{$new}\"");
            }
        }
    }

    public function down(): void
    {
        DB::table('stock_movements')->where('type', 'bought')->update(['type' => 'purchase']);
        DB::table('stock_movements')->where('type', 'used')->update(['type' => 'draw']);
        DB::table('stock_movements')->where('type', 'returned')->update(['type' => 'return']);
        DB::table('stock_movements')->where('type', 'moved')->update(['type' => 'transfer_out']);
        DB::table('stock_movements')->whereIn('type', ['stock_count', 'damaged_lost'])->update(['type' => 'adjustment']);
        DB::table('stock_movements')->where('type', 'opening_stock')->update(['type' => 'initial_count']);
        DB::table('stock_movements')->where('type', 'undone')->update(['type' => 'reversal']);

        Schema::dropIfExists('stock_movement_line_part_unit');
        Schema::dropIfExists('part_units');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_count_id');
            $table->dropConstrainedForeignId('ticket_issue_id');
            $table->dropConstrainedForeignId('payment_line_id');
        });

        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
