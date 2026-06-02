<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['warranty_id', 'ticket_issue_id'], 'warr_ti_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_ticket_issue');
    }
};
