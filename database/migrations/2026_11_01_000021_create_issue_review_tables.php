<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The records the review, inspection and approval steps leave behind.
 *
 * - `issue_info_requests`: "we need a photo of the label" and the store's
 *   answer. Answered by the store manager in the dashboard, or by the
 *   specialist on their behalf after a phone call (`answered_via`).
 * - `issue_inspections`: one per look at the problem. An issue can have
 *   several (a second opinion); each has its technician, when it is booked,
 *   what was found, the recommended repair, parts needed and the quote.
 * - `issue_approvals`: the decision on a quote. The level is worked out from
 *   the amount and the thresholds in `maintenance_settings`; who approved is a
 *   NAME today, with an optional user id so real approver roles can be
 *   enforced later without changing the table.
 * - `maintenance_settings`: small key/value store for numbers the owner may
 *   change (approval thresholds, how long an idle trip link lives).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('issue_info_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->text('question');
            $table->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('answer')->nullable();
            $table->string('answered_via', 16)->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('answered_at')->nullable();
            $table->boolean('mistaken')->default(false);
            $table->timestamps();

            $table->index(['ticket_issue_id', 'answered_at']);
        });

        Schema::create('issue_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->restrictOnDelete();
            $table->string('status', 16)->default('scheduled');
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->dateTime('done_at')->nullable();
            $table->text('findings')->nullable();
            $table->text('recommended_repair')->nullable();
            $table->text('parts_needed')->nullable();
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ticket_issue_id', 'status']);
            $table->index(['technician_id', 'scheduled_date']);
        });

        Schema::create('issue_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->foreignId('issue_inspection_id')->nullable()->constrained('issue_inspections')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('level', 32);
            $table->string('decision', 16);
            $table->string('approver_name');
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at');
            $table->text('note')->nullable();
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ticket_issue_id', 'decided_at']);
        });

        Schema::create('maintenance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_settings');
        Schema::dropIfExists('issue_approvals');
        Schema::dropIfExists('issue_inspections');
        Schema::dropIfExists('issue_info_requests');
    }
};
