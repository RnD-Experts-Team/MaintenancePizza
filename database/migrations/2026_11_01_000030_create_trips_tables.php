<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trips: how technicians clock their time in v2, after the ClockInOut flow.
 *
 * A TRIP is one outing of one technician that can cover several tickets at
 * several stores. The specialist creates it (scheduling a visit creates or
 * extends the technician's open trip) and sends the technician its link.
 * The link is a random token; only its hash is stored. It stops working after
 * `link_idle_hours` without use (a long job keeps it alive) or when the trip
 * is closed.
 *
 * Inside a trip:
 * - SESSIONS are clock-in → clock-out spans. A trip can have many: clocking
 *   out for the night and back in the next morning is two sessions of one
 *   trip. `open_marker` is TRUE on the one live session and NULL otherwise,
 *   under a unique (technician, open_marker) index -- the database itself
 *   guarantees a technician is clocked in at most once.
 * - EVENTS are what happened inside a session: break, travel, parts run, each
 *   a start and an end. Breaks are unpaid.
 * - STOPS are the stores on the trip: when he got there and left, the
 *   odometer on arrival, and the tickets he worked on there
 *   (`trip_stop_issues`, each done or not done with a reason).
 *
 * Every change made from the dashboard (not by the technician himself) is
 * written to `trip_audits` with the before and after values.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->date('planned_date')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedSmallInteger('link_idle_hours')->default(48);
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['technician_id', 'status']);
            $table->index(['status', 'planned_date']);
        });

        Schema::create('trip_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            $table->dateTime('clock_in');
            $table->dateTime('clock_out')->nullable();
            $table->boolean('open_marker')->nullable();
            $table->boolean('using_car')->default(false);
            $table->unsignedInteger('odometer_in')->nullable();
            $table->unsignedInteger('odometer_out')->nullable();
            $table->string('source', 16)->default('technician');
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['technician_id', 'open_marker'], 'trip_sessions_one_open_per_technician');
            $table->index(['trip_id', 'clock_in']);
            $table->index(['technician_id', 'clock_in']);
        });

        Schema::create('trip_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_session_id')->constrained('trip_sessions')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->dateTime('at');
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trip_session_id', 'at']);
        });

        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->string('other_store')->nullable();
            $table->string('status', 16)->default('planned');
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->unsignedInteger('arrival_odometer')->nullable();
            $table->text('not_done_reason')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('mistaken')->default(false);
            $table->timestamps();

            $table->index(['trip_id', 'sort_order']);
            $table->index('store_id');
        });

        Schema::create('trip_stop_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_stop_id')->constrained('trip_stops')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->string('purpose', 16)->default('work');
            $table->string('task_status', 16)->default('pending');
            $table->dateTime('done_at')->nullable();
            $table->text('not_done_reason')->nullable();
            $table->timestamps();

            $table->unique(['trip_stop_id', 'ticket_issue_id']);
            $table->index('ticket_issue_id');
        });

        Schema::create('trip_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 32);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trip_id', 'created_at']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->string('kind', 16)->default('work')->after('id');
            $table->foreignId('technician_id')->nullable()->after('kind')->constrained('technicians')->restrictOnDelete();
            $table->date('expected_completion_date')->nullable()->after('assigned_hour');
            $table->foreignId('trip_id')->nullable()->after('expected_completion_date')->constrained('trips')->nullOnDelete();
            $table->foreignId('issue_inspection_id')->nullable()->after('trip_id')->constrained('issue_inspections')->nullOnDelete();
            $table->index(['technician_id', 'assigned_date']);
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex(['technician_id', 'assigned_date']);
            $table->dropConstrainedForeignId('issue_inspection_id');
            $table->dropConstrainedForeignId('trip_id');
            $table->dropConstrainedForeignId('technician_id');
            $table->dropColumn(['kind', 'expected_completion_date']);
        });

        Schema::dropIfExists('trip_audits');
        Schema::dropIfExists('trip_stop_issues');
        Schema::dropIfExists('trip_stops');
        Schema::dropIfExists('trip_events');
        Schema::dropIfExists('trip_sessions');
        Schema::dropIfExists('trips');
    }
};
