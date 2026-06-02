<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            // Who attached this technician (pivots don't fire model events, set on attach()).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['technician_id', 'ticket_issue_id'], 'tech_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_ticket_issue');
    }
};
