<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_usage_id')->constrained('part_usages')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['part_usage_id', 'ticket_issue_id'], 'part_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_ticket_issue');
    }
};
