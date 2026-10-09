<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is good at what, and who to ring first.
 *
 * Per technician and catalog issue: a 1-5 star rating, notes, and a "call
 * first" pin. Per technician overall: the same -- and the overall pin is "the
 * GOAT", the person to ring for anything.
 *
 * `call_first` is stored as TRUE or NULL, never FALSE, under a unique index:
 * unique indexes ignore NULLs (MySQL and SQLite alike), so the database itself
 * guarantees at most ONE call-first per issue, and at most one overall.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('technician_issue_abilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('call_first')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['technician_id', 'issue_id']);
            $table->unique(['issue_id', 'call_first'], 'tia_one_call_first_per_issue');
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('category_id');
            $table->text('rating_notes')->nullable()->after('rating');
            $table->boolean('call_first')->nullable()->unique()->after('rating_notes');
            $table->foreignId('rating_updated_by')->nullable()->after('call_first')->constrained('users')->nullOnDelete();
            $table->timestamp('rating_updated_at')->nullable()->after('rating_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropUnique(['call_first']);
            $table->dropConstrainedForeignId('rating_updated_by');
            $table->dropColumn(['rating', 'rating_notes', 'call_first', 'rating_updated_at']);
        });

        Schema::dropIfExists('technician_issue_abilities');
    }
};
