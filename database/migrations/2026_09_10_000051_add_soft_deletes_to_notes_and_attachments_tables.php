<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixes a long-standing data-loss bug in the daily pay edit path.
     *
     * Editing an entry snapshots it into daily_pay_entry_revisions and then
     * removes the old notes and attachments. Neither model soft-deleted, so
     * those were HARD deletes: the rows vanished, the uploaded files stayed on
     * disk forever, and the snapshot captured moments earlier still contained
     * attachment URLs that now 404. The audit trail was already lossy.
     *
     * With soft deletes the same ->delete() calls become soft, the rows and
     * files survive, and historical snapshots keep resolving. Live views are
     * unaffected: the morphMany relations exclude trashed rows by default.
     *
     * attachments:prune is then the only place a file is ever unlinked.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
