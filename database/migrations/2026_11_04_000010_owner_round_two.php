<?php

use App\Support\NameKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's second round:
 *   - bookings and inspections take a time RANGE (from / to), not one hour;
 *   - an inspection can carry its own fee, typed when it is booked;
 *   - how a payment was made comes from an editable catalog (payment_methods),
 *     seeded with the old fixed list; `payments.method` stays as it was for
 *     older readers and is no longer written;
 *   - a stock movement can name the technician who did it.
 */
return new class extends Migration
{
    /** The old fixed list, in its order. */
    private const METHODS = [
        'cash' => 'Cash',
        'check' => 'Check',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'zelle' => 'Zelle',
        'other' => 'Other',
    ];

    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->time('assigned_hour_to')->nullable()->after('assigned_hour');
        });
        Schema::table('issue_inspections', function (Blueprint $table) {
            $table->time('scheduled_time_to')->nullable()->after('scheduled_time');
            $table->decimal('inspection_fee', 12, 2)->nullable()->after('estimated_cost');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $sort = 0;
        $ids = [];
        foreach (self::METHODS as $code => $name) {
            $ids[$code] = DB::table('payment_methods')->insertGetId([
                'name' => $name,
                'name_key' => NameKey::of($name),
                'sort' => $sort++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('supplier_id')->constrained('payment_methods')->restrictOnDelete();
        });
        foreach ($ids as $code => $id) {
            DB::table('payments')->where('method', $code)->update(['payment_method_id' => $id]);
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('technician_id')->nullable()->after('paid_by_technician_id')->constrained('technicians')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });
        Schema::dropIfExists('payment_methods');
        Schema::table('issue_inspections', function (Blueprint $table) {
            $table->dropColumn(['scheduled_time_to', 'inspection_fee']);
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('assigned_hour_to');
        });
    }
};
