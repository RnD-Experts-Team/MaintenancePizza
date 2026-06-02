<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_entry_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_entry_id')->constrained('attendance_entries')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['attendance_entry_id', 'ticket_issue_id'], 'att_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_entry_ticket_issue');
    }
};
