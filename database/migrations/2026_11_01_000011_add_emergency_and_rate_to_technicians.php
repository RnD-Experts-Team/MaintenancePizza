<?php

use App\Services\LegacyConversion\LegacyMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a technician is.
 *
 * - `emergency_available` + `emergency_notes`: "you can call me at night".
 *   Pickers on an urgent issue list these first.
 * - `hourly_rate`: the default for a By-the-hour payment line; each line can
 *   still say otherwise.
 * - `contact_person`: who to ask for when the technician is a company.
 * - `user_id`: empty today. Technicians have no logins yet; when they do, the
 *   account attaches here and every trip, session and payment already keyed
 *   on the technician stays where it is.
 *
 * The typed OVERALL rating goes: the overall rating is now the average of the
 * per-issue ratings. Whatever was typed is kept as a note on the technician so
 * nothing anyone wrote is lost. The overall "call first" pin goes with it --
 * call first is per issue now.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('phone');
            $table->decimal('hourly_rate', 10, 4)->nullable()->after('contact_person');
            $table->boolean('emergency_available')->default(false)->after('hourly_rate');
            $table->text('emergency_notes')->nullable()->after('emergency_available');
            $table->foreignId('user_id')->nullable()->after('emergency_notes')->constrained('users')->nullOnDelete();
            $table->index('emergency_available');
        });

        $this->keepOverallRatingsAsNotes();

        Schema::table('technicians', function (Blueprint $table) {
            $table->dropUnique(['call_first']);
            $table->dropConstrainedForeignId('rating_updated_by');
            $table->dropColumn(['rating', 'rating_notes', 'call_first', 'rating_updated_at']);
        });
    }

    private function keepOverallRatingsAsNotes(): void
    {
        DB::table('technicians')
            ->where(fn ($q) => $q->whereNotNull('rating')->orWhereNotNull('rating_notes')->orWhereNotNull('call_first'))
            ->orderBy('id')
            ->each(function ($t) {
                $parts = [];

                if ($t->rating !== null) {
                    $parts[] = "Overall rating before it became an average: {$t->rating}/5.";
                }
                if ($t->call_first) {
                    $parts[] = 'Was pinned as the overall "call first".';
                }
                if ($t->rating_notes !== null && trim($t->rating_notes) !== '') {
                    $parts[] = 'Rating notes: '.trim($t->rating_notes);
                }

                DB::table('notes')->insert([
                    'notable_type' => 'App\\Models\\Technician',
                    'notable_id' => $t->id,
                    'type' => 'legacy_overall_rating',
                    'body' => implode("\n", $parts),
                    'is_private' => true,
                    'created_by' => $t->rating_updated_by,
                    'created_at' => $t->rating_updated_at ?? now(),
                    'updated_at' => now(),
                ]);

                LegacyMap::report('technicians', "Kept the overall rating of technician #{$t->id} as a private note");
            });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('rating_notes')->nullable();
            $table->boolean('call_first')->nullable()->unique();
            $table->foreignId('rating_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rating_updated_at')->nullable();
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->dropIndex(['emergency_available']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['contact_person', 'hourly_rate', 'emergency_available', 'emergency_notes']);
        });
    }
};
