<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_entry_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_entry_id')->constrained('pay_entries')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pay_entry_id', 'ticket_issue_id'], 'pay_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_entry_ticket_issue');
    }
};
