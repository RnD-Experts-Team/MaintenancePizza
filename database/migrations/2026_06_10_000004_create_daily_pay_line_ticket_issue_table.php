<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_line_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_pay_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['daily_pay_line_id', 'ticket_issue_id'], 'dpline_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_line_ticket_issue');
    }
};
