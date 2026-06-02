<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            // Required when transitioning to "deferred".
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('ticket_issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_status_changes');
    }
};
