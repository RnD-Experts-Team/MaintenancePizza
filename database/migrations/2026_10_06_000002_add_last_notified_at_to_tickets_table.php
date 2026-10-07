<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a ticket's Store Managers were last told it changed (see
 * tickets:send-update-notifications). Existing tickets start as told up to
 * their last change, so the first run does not report their whole history.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('last_notified_at')->nullable()->after('updated_at');
        });

        DB::table('tickets')->update(['last_notified_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('last_notified_at');
        });
    }
};
