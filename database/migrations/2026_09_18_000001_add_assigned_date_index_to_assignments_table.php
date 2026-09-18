<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Serves two readers that both pin mistaken and bound assigned_date: the
     * ?assigned_from/assigned_to ticket filter, and the overdue count in
     * TicketAnalyticsService. Equality column first, range column second --
     * the other order would leave the range unable to narrow anything.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->index(['mistaken', 'assigned_date'], 'assignments_mistaken_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('assignments_mistaken_date_index');
        });
    }
};
