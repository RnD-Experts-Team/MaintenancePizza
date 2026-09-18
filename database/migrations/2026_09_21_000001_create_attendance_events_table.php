<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance becomes an append-only EVENT LEDGER.
 *
 * WHAT WAS WRONG. An entry held four start/end pairs as eight columns, which
 * made it a container you had to fill in rather than a record of what happened.
 * Two consequences, both real:
 *
 *   1. One session could hold ONE break, ONE travel and ONE parts run. A
 *      technician who took two breaks needed a second entry, which then looked
 *      like a second visit.
 *   2. There was no update path at all -- after createAttendance() the only
 *      mutation was mistaken = true -- so recording a clock-in and then wanting
 *      to add "he set off at 08:30" meant flagging the record wrong and typing
 *      it again. That is the complaint this migration exists to answer.
 *
 * THE SHAPE. Each event is its own row. The entry survives as the SESSION --
 * one clock-in to one clock-out -- and keeps its id, its issues and, crucially,
 * its pay pivot, so daily_pay_payment_attendance_entry and the dppay_att_unique
 * anti-double-pay index do not move at all.
 *
 * This is the same ledger-plus-cache shape the stock module already uses
 * (stock_movements -> stock_balances), so it is consistent rather than novel.
 *
 * WHY start_clock AND end_clock STAY. They become a cache recomputed from the
 * events on every write. They are not decoration: DailyPayEntryService runs
 * MIN(ae.start_clock), MAX(ae.start_clock) and a BETWEEN over them in SQL for
 * the unpaid-work query, and DailyPayAggregationService::overlaps() filters on
 * both. Deriving them would turn two column reads into a correlated subquery on
 * every pay run. Keeping them means those files need no change whatsoever.
 *
 * The other six columns have no such claim on them and are dropped.
 */
return new class extends Migration
{
    /**
     * The eight columns, mapped to the event kind each one becomes.
     *
     * @var array<string, string>
     */
    private const COLUMN_KINDS = [
        'start_clock' => 'clock_in',
        'end_clock' => 'clock_out',
        'start_travel' => 'travel_start',
        'end_travel' => 'travel_end',
        'start_break' => 'break_start',
        'end_break' => 'break_end',
        'start_parts_run' => 'parts_run_start',
        'end_parts_run' => 'parts_run_end',
    ];

    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_entry_id')->constrained('attendance_entries')->cascadeOnDelete();
            $table->string('kind', 32);
            // NOT nullable, unlike the columns this replaces. An event with no
            // time is not an event -- the absence of one IS how "he has not
            // clocked out yet" is now said, rather than a null in a column.
            $table->dateTime('at');
            // The universal audit flag. A struck event stays visible and stays
            // in the ledger; it simply stops counting.
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The walk is always "this session's events in time order".
            $table->index(['attendance_entry_id', 'at'], 'attendance_event_walk_index');
        });

        $this->carryOverClocks();

        Schema::table('attendance_entries', function (Blueprint $table) {
            // start_clock and end_clock deliberately survive -- see above.
            $table->dropColumn([
                'start_break',
                'end_break',
                'start_parts_run',
                'end_parts_run',
                'start_travel',
                'end_travel',
            ]);
        });
    }

    /**
     * Every recorded timestamp becomes one event.
     *
     * Nulls are skipped, so an entry that only ever had a clock-in produces one
     * event and reads afterwards as an open session -- which is what it always
     * was, just now said in a way the system can act on.
     */
    private function carryOverClocks(): void
    {
        $columns = array_keys(self::COLUMN_KINDS);

        DB::table('attendance_entries')
            ->select(array_merge(['id', 'created_by', 'created_at'], $columns))
            ->orderBy('id')
            ->chunk(500, function ($entries) {
                $rows = [];

                foreach ($entries as $entry) {
                    foreach (self::COLUMN_KINDS as $column => $kind) {
                        if ($entry->{$column} === null) {
                            continue;
                        }

                        $rows[] = [
                            'attendance_entry_id' => $entry->id,
                            'kind' => $kind,
                            'at' => $entry->{$column},
                            'mistaken' => false,
                            'created_by' => $entry->created_by,
                            // The entry's own timestamps, not now(): these
                            // events did not happen at migration time, and
                            // stamping them so would make the audit trail lie.
                            'created_at' => $entry->created_at,
                            'updated_at' => $entry->created_at,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('attendance_events')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::table('attendance_entries', function (Blueprint $table) {
            $table->dateTime('start_break')->nullable()->after('end_clock');
            $table->dateTime('end_break')->nullable()->after('start_break');
            $table->dateTime('start_parts_run')->nullable()->after('end_break');
            $table->dateTime('end_parts_run')->nullable()->after('start_parts_run');
            $table->dateTime('start_travel')->nullable()->after('end_parts_run');
            $table->dateTime('end_travel')->nullable()->after('start_travel');
        });

        // Put back the FIRST of each kind. Lossy by nature and openly so: eight
        // columns cannot hold a session with two breaks in it, which is the
        // whole reason for going the other way.
        foreach (self::COLUMN_KINDS as $column => $kind) {
            if (in_array($column, ['start_clock', 'end_clock'], true)) {
                continue;
            }

            DB::table('attendance_entries')->update([
                $column => DB::raw(
                    '(select min(at) from attendance_events'
                    ." where attendance_events.attendance_entry_id = attendance_entries.id"
                    ." and attendance_events.kind = '{$kind}'"
                    .' and attendance_events.mistaken = 0)'
                ),
            ]);
        }

        Schema::dropIfExists('attendance_events');
    }
};
