<?php

use App\Services\MigrationV2\AttendanceToTrips;
use App\Services\MigrationV2\DiagnosesAndAssignments;
use App\Services\MigrationV2\PartUsagesToV2;
use App\Services\MigrationV2\PayToPayments;
use Illuminate\Database\Migrations\Migration;

/**
 * Converts the old records into the v2 ones. Order matters: payments follow
 * the trip sessions attendance became, and parts follow what payments already
 * paid back. Each converter is idempotent (see LegacyMap), so a failed run
 * can simply be run again. The old tables are left in place, unused, for a
 * later cleanup release; `php artisan maintenance:v2-report` lists what was
 * converted, renamed or guessed.
 */
return new class extends Migration {
    public function up(): void
    {
        app(AttendanceToTrips::class)->run();
        app(DiagnosesAndAssignments::class)->run();
        app(PayToPayments::class)->run();
        app(PartUsagesToV2::class)->run();
    }

    public function down(): void
    {
        // One-way: the old rows are untouched, so rolling the schema back
        // drops the converted rows with their tables.
    }
};
