<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revision snapshots are a JSON dump of whatever shape the entry had at the
     * time. Everything written before the payments level is shape v1
     * ({date, lines: [...]}); everything after is v2
     * ({date, payments: [{..., lines: [...]}]}).
     *
     * Without this marker the SPA cannot tell which renderer a historical
     * revision needs, and would quietly show an empty revision for every pay
     * sheet edited before today. Existing snapshots are NEVER rewritten — the
     * default of 1 is what makes them readable.
     */
    public function up(): void
    {
        Schema::table('daily_pay_entry_revisions', function (Blueprint $table) {
            $table->unsignedTinyInteger('schema_version')->default(1)->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('daily_pay_entry_revisions', function (Blueprint $table) {
            $table->dropColumn('schema_version');
        });
    }
};
