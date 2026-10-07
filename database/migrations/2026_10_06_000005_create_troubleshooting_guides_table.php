<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Troubleshooting guides: what a store should try before opening a ticket for
 * a catalog issue ("Oven not heating: check the breaker, relight the pilot...").
 *
 * One guide per catalog issue -- ordered plain-text steps, an optional link (a
 * video, a PDF), and files through the usual polymorphic attachments.
 * `version` goes up on every edit, so a ticket can record exactly which steps
 * the manager confirmed trying.
 *
 * On the ticket issue: when the manager confirmed, and a snapshot of the guide
 * as they saw it -- the guide may change later; what they were asked to try
 * must not.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('troubleshooting_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->unique()->constrained('issues')->cascadeOnDelete();
            $table->json('steps');
            $table->string('link_url', 2048)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->timestamp('troubleshooting_confirmed_at')->nullable()->after('parent_id');
            $table->json('troubleshooting_snapshot')->nullable()->after('troubleshooting_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropColumn(['troubleshooting_confirmed_at', 'troubleshooting_snapshot']);
        });

        Schema::dropIfExists('troubleshooting_guides');
    }
};
