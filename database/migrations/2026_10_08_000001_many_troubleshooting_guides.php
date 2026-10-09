<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Troubleshooting, second shape (owner, 2026-10-08).
 *
 * - An issue has MANY guides, one per specific problem ("Oven" -> "Won't
 *   heat", "Door won't close"), so a guide gets a title and `issue_id` stops
 *   being unique.
 * - Steps become rows, so each step can carry its own files through the usual
 *   polymorphic attachments. The guide keeps its link and its own files.
 * - The ticket issue records how troubleshooting ended -- tried and still
 *   broken, or none of the guides described the problem -- and which guide,
 *   when the manager picked one.
 * - "This fixed it" is logged on its own: no ticket is ever opened for it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->string('title')->default('General')->after('issue_id');
        });

        // The unique index backs the foreign key on MySQL: add a plain one
        // first, or MySQL refuses to drop it.
        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->index('issue_id', 'troubleshooting_guides_issue_index');
        });
        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->dropUnique(['issue_id']);
        });

        Schema::create('troubleshooting_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('troubleshooting_guide_id')->constrained('troubleshooting_guides')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('body');
            $table->timestamps();
            $table->index(['troubleshooting_guide_id', 'position']);
        });

        $now = now();
        foreach (DB::table('troubleshooting_guides')->select(['id', 'steps'])->orderBy('id')->get() as $guide) {
            $steps = array_values(array_filter(
                array_map(fn ($s) => trim((string) $s), (array) json_decode((string) $guide->steps, true)),
                fn ($s) => $s !== ''
            ));
            foreach ($steps as $i => $body) {
                DB::table('troubleshooting_steps')->insert([
                    'troubleshooting_guide_id' => $guide->id,
                    'position' => $i,
                    'body' => $body,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->dropColumn('steps');
        });

        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->string('troubleshooting_outcome', 20)->nullable()->after('troubleshooting_confirmed_at');
            $table->foreignId('troubleshooting_guide_id')->nullable()->after('troubleshooting_outcome')
                ->constrained('troubleshooting_guides')->nullOnDelete();
        });

        // Tickets confirmed under the one-checkbox gate tried the one guide.
        DB::table('ticket_issues')->whereNotNull('troubleshooting_confirmed_at')->update(['troubleshooting_outcome' => 'tried']);

        Schema::create('troubleshooting_fixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('troubleshooting_guide_id')->nullable()->constrained('troubleshooting_guides')->nullOnDelete();
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('troubleshooting_fixes');

        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('troubleshooting_guide_id');
            $table->dropColumn('troubleshooting_outcome');
        });

        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->json('steps')->nullable();
        });

        foreach (DB::table('troubleshooting_guides')->pluck('id') as $id) {
            $steps = DB::table('troubleshooting_steps')->where('troubleshooting_guide_id', $id)->orderBy('position')->pluck('body')->all();
            DB::table('troubleshooting_guides')->where('id', $id)->update(['steps' => json_encode($steps)]);
        }

        Schema::dropIfExists('troubleshooting_steps');

        // Back to one guide per issue: keep the first of each.
        $keep = DB::table('troubleshooting_guides')->selectRaw('MIN(id) as id')->groupBy('issue_id')->pluck('id');
        DB::table('troubleshooting_guides')->whereNotIn('id', $keep)->delete();

        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->unique('issue_id');
        });
        Schema::table('troubleshooting_guides', function (Blueprint $table) {
            $table->dropIndex('troubleshooting_guides_issue_index');
            $table->dropColumn('title');
        });
    }
};
