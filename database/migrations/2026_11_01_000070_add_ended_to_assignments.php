<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking ends when its issue is closed or cancelled before the day comes
 * (TripService::releaseFinishedIssue): kept for the record, no longer standing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dateTime('ended_at')->nullable()->after('mistaken');
            $table->string('ended_reason')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['ended_at', 'ended_reason']);
        });
    }
};
