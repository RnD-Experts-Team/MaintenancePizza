<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locked ("private") notes, MOS only: returned by the ticket's "all notes"
 * read and by nothing else, files included. locked_by / locked_at record who
 * last locked or unlocked one, and when.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('body')->index();
            $table->foreignId('locked_by')->nullable()->after('is_private')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropIndex(['is_private']);
            $table->dropColumn(['is_private', 'locked_at']);
        });
    }
};
