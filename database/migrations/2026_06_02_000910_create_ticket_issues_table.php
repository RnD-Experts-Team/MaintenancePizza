<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            // Either a catalog issue OR a free-text "other" issue.
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('other_title')->nullable();
            $table->string('priority');
            $table->text('description');
            $table->string('status')->default('pending');
            // Deferral child points back at the issue it replaces.
            $table->foreignId('parent_id')->nullable()->constrained('ticket_issues')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_issues');
    }
};
