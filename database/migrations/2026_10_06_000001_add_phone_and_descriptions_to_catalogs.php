<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the catalog screen was already sending into the void: a technician's
 * phone number, and a description on categories and parts. The requests
 * dropped them silently because there was nowhere to put them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('name');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('parts', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('technicians', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
