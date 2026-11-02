<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Who answered" is an optional name typed in, not a store / specialist
 * choice. The account that recorded it is still kept (`answered_by`,
 * `store_confirmed_by`). Rows that said "specialist" keep that meaning
 * as text.
 */
return new class extends Migration
{
    private const BY_PHONE = 'By phone, recorded by the office';

    public function up(): void
    {
        Schema::table('issue_info_requests', function (Blueprint $table) {
            $table->string('answered_by_name', 120)->nullable()->after('answer');
        });
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->string('store_confirmed_by_name', 120)->nullable()->after('store_confirmed_working');
        });

        DB::table('issue_info_requests')->where('answered_via', 'specialist')->update(['answered_by_name' => self::BY_PHONE]);
        DB::table('ticket_issues')->where('store_confirmed_via', 'specialist')->update(['store_confirmed_by_name' => self::BY_PHONE]);

        Schema::table('issue_info_requests', function (Blueprint $table) {
            $table->dropColumn('answered_via');
        });
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropColumn('store_confirmed_via');
        });
    }

    public function down(): void
    {
        Schema::table('issue_info_requests', function (Blueprint $table) {
            $table->string('answered_via', 16)->nullable();
        });
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->string('store_confirmed_via', 16)->nullable()->after('store_confirmed_working');
        });

        DB::table('issue_info_requests')->whereNotNull('answered_at')->update(['answered_via' => 'store']);
        DB::table('issue_info_requests')->where('answered_by_name', self::BY_PHONE)->update(['answered_via' => 'specialist']);
        DB::table('ticket_issues')->whereNotNull('store_confirmed_at')->update(['store_confirmed_via' => 'store']);
        DB::table('ticket_issues')->where('store_confirmed_by_name', self::BY_PHONE)->update(['store_confirmed_via' => 'specialist']);

        Schema::table('issue_info_requests', function (Blueprint $table) {
            $table->dropColumn('answered_by_name');
        });
        Schema::table('ticket_issues', function (Blueprint $table) {
            $table->dropColumn('store_confirmed_by_name');
        });
    }
};
