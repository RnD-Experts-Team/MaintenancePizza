<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket can be for one of our storage locations instead of a store (a
 * broken shelf, the storage's own door): `tickets.storage_location_id`. A
 * ticket is at a store, at a storage location, or at a place written in
 * words (`other_store`) -- exactly one of the three.
 *
 * A technician going there makes a trip stop at that storage location, so
 * `trip_stops` carries the same column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('storage_location_id')->nullable()->after('other_store')
                ->constrained('storage_locations')->restrictOnDelete();
        });

        Schema::table('trip_stops', function (Blueprint $table) {
            $table->foreignId('storage_location_id')->nullable()->after('other_store')
                ->constrained('storage_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trip_stops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_location_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_location_id');
        });
    }
};
