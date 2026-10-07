<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "What changed since X" is asked of updated_at -- by the Store Manager
 * notifications every run, and by the analytics page. Indexed so those reads
 * touch only recent rows, not every ticket ever opened.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('updated_at');
        });

        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });

        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });
    }
};
