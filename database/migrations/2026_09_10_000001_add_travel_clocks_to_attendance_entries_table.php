<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_entries', function (Blueprint $table) {
            // Travel between locations. Like the other pairs, intentionally
            // unconstrained: the dispatcher may set either half, or neither.
            // Historical rows stay NULL — travel was never recorded here before,
            // and must not be inferred from daily_pay_lines.travel_time.
            $table->dateTime('start_travel')->nullable()->after('end_parts_run');
            $table->dateTime('end_travel')->nullable()->after('start_travel');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_entries', function (Blueprint $table) {
            $table->dropColumn(['start_travel', 'end_travel']);
        });
    }
};
