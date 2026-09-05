<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->string('assigned_priority')->nullable()->after('priority');
            $table->index('assigned_priority');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropIndex(['assigned_priority']);
            $table->dropColumn('assigned_priority');
        });
    }
};
