<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DML only. Historical rows recorded one lump `cost` with no quantity, so
     * the honest reading is "one of them, at that price": quantity 1,
     * unit_cost = cost, which leaves cost = quantity * unit_cost intact.
     *
     * The `source`/`paid_by` defaults ('purchased'/'us') set by the previous
     * migration are already the correct historical reading, so there is
     * nothing to update for those.
     *
     * Idempotent: the guard means re-running can only ever touch rows that
     * still look untouched, so it will never overwrite a row a human has since
     * corrected.
     */
    public function up(): void
    {
        DB::table('part_usages')
            ->where('quantity', 1)
            ->where('unit_cost', 0)
            ->update(['unit_cost' => DB::raw('cost')]);
    }

    /**
     * Deliberately a no-op: reversing this would discard the unit prices,
     * and `cost` — the column everything actually reads — is untouched either
     * way.
     */
    public function down(): void
    {
        //
    }
};
