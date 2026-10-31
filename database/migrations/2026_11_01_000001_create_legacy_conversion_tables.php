<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance conversion bookkeeping.
 *
 * `legacy_conversion_map` remembers which new row each old row became, so every
 * converter is idempotent (a row already mapped is skipped) and later
 * converters can follow earlier ones (a pay claim on an attendance entry finds
 * the trip session that entry became).
 *
 * `legacy_conversion_report` is what `php artisan maintenance:conversion-report` prints:
 * every rename, every guess, every row that could not be mapped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_conversion_map', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_table', 64);
            $table->unsignedBigInteger('legacy_id');
            $table->string('new_table', 64);
            $table->unsignedBigInteger('new_id');
            $table->timestamps();

            // new_id is part of the key: one old row can become several new
            // rows of the same table (an entry clocked out and back in is two
            // sessions), and every one of them must be remembered.
            $table->unique(['legacy_table', 'legacy_id', 'new_table', 'new_id'], 'legacy_conversion_map_unique');
            $table->index(['new_table', 'new_id']);
        });

        Schema::create('legacy_conversion_report', function (Blueprint $table) {
            $table->id();
            $table->string('step', 64)->index();
            $table->string('level', 16)->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_conversion_report');
        Schema::dropIfExists('legacy_conversion_map');
    }
};
