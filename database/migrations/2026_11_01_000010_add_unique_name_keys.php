<?php

use App\Services\MigrationV2\UniqueNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No two technicians, technician categories or parts may share a name, ignoring
 * capitals and extra spaces (owner, 2026-10-30).
 *
 * The rule lives in the database as a unique `name_key`. Names that already
 * collide are renamed "Name (2)", "Name (3)" in id order -- the oldest keeps
 * its name -- and every rename is written to the conversion report so somebody
 * can merge or rename them properly. Soft-deleted rows count: restoring one
 * must not be able to create a duplicate. The renaming itself lives in
 * App\Services\MigrationV2\UniqueNames, so it can be tested on its own.
 */
return new class extends Migration {
    private const TABLES = ['technicians', 'categories', 'parts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('name_key')->nullable()->after('name');
            });

            app(UniqueNames::class)->fill($table);

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unique('name_key', "{$table}_name_key_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_name_key_unique");
                $t->dropColumn('name_key');
            });
        }
    }
};
