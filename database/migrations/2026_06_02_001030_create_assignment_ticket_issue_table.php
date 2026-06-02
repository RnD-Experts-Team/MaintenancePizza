<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['assignment_id', 'ticket_issue_id'], 'asgn_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_ticket_issue');
    }
};
