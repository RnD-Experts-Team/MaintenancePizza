<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where inside a location a part actually sits.
     *
     * A location answers "Storage A". It could not answer "shelf C, section 5,
     * column Z", which is the thing you need in order to walk over and pick the
     * part up. Each location defines its OWN slots, so a van can have "front
     * rack" and a depot can have "aisle 3 / bay 2" without either being forced
     * into the other's vocabulary.
     *
     * THE SLOT IS NOT A STOCK DIMENSION. Quantities stay per (part, location) --
     * nothing about counting changes, no movement has to name a slot, and every
     * existing query is untouched. A slot records where a part LIVES, which is a
     * findability question, not an accounting one.
     *
     * That is why storage_slot_id goes on stock_balances: that row already IS
     * the (part, location) pair, so it is the natural place to say "this part,
     * at this location, is on that shelf". No second table to keep in step.
     *
     * CAVEAT, stated plainly: stock_balances is otherwise a CACHE of the ledger,
     * written only by StockService. This is the one user-entered column on it.
     * `stock:reconcile --fix` uses updateOrCreate with only `quantity`, so it
     * preserves the slot -- but anything new that rebuilds balance rows wholesale
     * must remember to carry it across.
     */
    public function up(): void
    {
        Schema::create('storage_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->cascadeOnDelete();
            // Free text on purpose. "Shelf C", "Section 5 / Col Z", "behind the
            // door" -- whatever the people who work there actually call it.
            $table->string('name');
            // Optional short form for tight table cells, like the location's own.
            $table->string('code', 64)->nullable();
            // Hand-ordered, so a location's slots list in walking order rather
            // than alphabetically.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Unique per location among live slots, not globally -- two
            // locations may each have a "Shelf A" and that is not a clash.
            $table->unique(['storage_location_id', 'name'], 'storage_slot_name_unique');
            $table->index(['storage_location_id', 'sort_order'], 'storage_slot_order_index');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            // nullOnDelete, not restrict: retiring a slot must not strand the
            // stock. The part stays where it is, we just stop claiming to know
            // which shelf.
            $table->foreignId('storage_slot_id')->nullable()->after('storage_location_id')
                ->constrained('storage_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_slot_id');
        });

        Schema::dropIfExists('storage_slots');
    }
};
