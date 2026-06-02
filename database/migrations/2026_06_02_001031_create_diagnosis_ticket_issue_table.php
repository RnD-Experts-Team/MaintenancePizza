<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnosis_id')->constrained('diagnoses')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['diagnosis_id', 'ticket_issue_id'], 'diag_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosis_ticket_issue');
    }
};
