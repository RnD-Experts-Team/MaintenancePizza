<?php

use App\Services\MigrationV2\LegacyMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The issue workflow of v2, after the owner's flowchart:
 *
 *   new → in_review → (waiting_on_store) → (inspection) → (waiting_approval)
 *       → scheduled → work_done → closed          (or cancelled at any point)
 *
 * The columns are what each step writes down:
 * - submission: `impact` (how badly the store is affected), the contact to
 *   call back;
 * - review: `target_completion_at` and `next_action`, shown on the ticket;
 * - work: `work_performed`;
 * - step 10: `store_confirmed_*`, the store saying "it works now" (or not),
 *   either themselves or through the specialist (`via`);
 * - closing: `resolution`, how it ended.
 *
 * Old statuses are mapped once, here, and the status history is mapped the
 * same way so its labels still read.
 */
return new class extends Migration {
    private const STATUS_MAP = [
        'pending' => 'new',
        'assigned' => 'scheduled',
        'in_progress' => 'scheduled',
        'waiting' => 'waiting_on_store',
        'complete' => 'closed',
        'deferred' => 'closed',
        'cancelled' => 'cancelled',
    ];

    public function up(): void
    {
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->string('impact', 32)->nullable()->after('description');
            $table->string('contact_name')->nullable()->after('impact');
            $table->string('contact_phone', 32)->nullable()->after('contact_name');
            $table->dateTime('target_completion_at')->nullable()->after('contact_phone');
            $table->string('next_action')->nullable()->after('target_completion_at');
            $table->text('work_performed')->nullable()->after('next_action');
            $table->string('resolution', 32)->nullable()->after('work_performed');
            $table->dateTime('store_confirmed_at')->nullable()->after('resolution');
            $table->boolean('store_confirmed_working')->nullable()->after('store_confirmed_at');
            $table->string('store_confirmed_via', 16)->nullable()->after('store_confirmed_working');
            $table->foreignId('store_confirmed_by')->nullable()->after('store_confirmed_via')->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable()->after('store_confirmed_by');
        });

        DB::table('ticket_issues')->where('status', 'deferred')->update(['resolution' => 'deferred']);
        DB::table('ticket_issues')->where('status', 'complete')->update(['resolution' => 'repaired']);

        foreach (self::STATUS_MAP as $old => $new) {
            $count = DB::table('ticket_issues')->where('status', $old)->update(['status' => $new]);
            DB::table('issue_status_changes')->where('from_status', $old)->update(['from_status' => $new]);
            DB::table('issue_status_changes')->where('to_status', $old)->update(['to_status' => $new]);

            if ($count > 0) {
                LegacyMap::report('statuses', "{$count} issue(s) moved from \"{$old}\" to \"{$new}\"");
            }
        }

        // When a closed issue closed: its last status change into closed.
        DB::table('ticket_issues')->whereIn('status', ['closed', 'cancelled'])->orderBy('id')->each(function ($issue) {
            $at = DB::table('issue_status_changes')
                ->where('ticket_issue_id', $issue->id)
                ->whereIn('to_status', ['closed', 'cancelled'])
                ->max('created_at');

            DB::table('ticket_issues')->where('id', $issue->id)->update(['closed_at' => $at ?? $issue->updated_at]);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_confirmed_by');
            $table->dropColumn([
                'impact', 'contact_name', 'contact_phone', 'target_completion_at', 'next_action',
                'work_performed', 'resolution', 'store_confirmed_at', 'store_confirmed_working',
                'store_confirmed_via', 'closed_at',
            ]);
        });
    }
};
