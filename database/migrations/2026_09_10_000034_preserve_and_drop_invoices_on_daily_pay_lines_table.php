<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `invoices` is being removed from daily pay — it never entered any total
     * and nothing reads it.
     *
     * Dropping a column throws its contents away, so every non-null value is
     * first written onto its own line as a note (type `legacy_invoices`). The
     * figures stay visible to anyone looking at an old pay sheet; they simply
     * stop being a field.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('daily_pay_lines', 'invoices')) {
            return;
        }

        DB::table('daily_pay_lines')
            ->whereNotNull('invoices')
            ->orderBy('id')
            ->chunk(500, function ($lines) {
                $notes = [];

                foreach ($lines as $line) {
                    $notes[] = [
                        'notable_type' => 'App\Models\DailyPayLine',
                        'notable_id' => $line->id,
                        'type' => 'legacy_invoices',
                        'body' => 'Invoices recorded before the field was removed: ' . $line->invoices,
                        'created_by' => $line->created_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($notes !== []) {
                    DB::table('notes')->insert($notes);
                }
            });

        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->dropColumn('invoices');
        });
    }

    /**
     * Restores the column but not the values — they live in the notes written
     * above, which are left alone so nothing is lost either way.
     */
    public function down(): void
    {
        Schema::table('daily_pay_lines', function (Blueprint $table) {
            $table->decimal('invoices', 10, 2)->nullable()->after('gas');
        });
    }
};
