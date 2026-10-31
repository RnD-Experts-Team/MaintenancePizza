<?php

use App\Services\LegacyConversion\AttendanceToTrips;
use App\Services\LegacyConversion\DiagnosesAndAssignments;
use App\Services\LegacyConversion\PartUsagesToPurchases;
use App\Services\LegacyConversion\PayToPayments;
use Illuminate\Database\Migrations\Migration;

/**
 * Converts the old records into the new ones. Order matters: payments follow
 * the trip sessions attendance became, and parts follow what payments already
 * paid back. Each converter is idempotent (see LegacyMap), so a failed run
 * can simply be run again. The old tables are left in place, unused, for a
 * later cleanup release; `php artisan maintenance:conversion-report` lists what was
 * converted, renamed or guessed.
 */
return new class extends Migration {
    public function up(): void
    {
        app(AttendanceToTrips::class)->run();
        app(DiagnosesAndAssignments::class)->run();
        app(PayToPayments::class)->run();
        app(PartUsagesToPurchases::class)->run();
    }

    public function down(): void
    {
        // One-way: the old rows are untouched, so rolling the schema back
        // drops the converted rows with their tables.
    }
};
