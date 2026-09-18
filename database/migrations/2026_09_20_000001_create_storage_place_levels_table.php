<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where inside a location a part actually sits -- said properly this time.
 *
 * WHAT WAS WRONG WITH SLOTS. `storage_slots` gave a location one flat list of
 * names, so "shelf C, row 8, column 5" had to be typed as a single string and
 * could never be asked about. Worse, nothing could ever SET one: no request
 * class, controller, service or route wrote `stock_balances.storage_slot_id`,
 * so the column was reachable from tinker and from tests and nowhere else. The
 * catalogue, the read path and the display all existed; the assignment -- the
 * entire point -- was never built.
 *
 * WHAT REPLACES IT. Each location names its own LEVELS -- Shelf, Row, Column,
 * Section, as many as it wants -- and each level declares its own VALUES.
 * A part then carries at most one value per level, and every level is optional,
 * so a thing that lives in a column and nothing else says exactly that.
 *
 * ONE ADDRESS PER PART PER LOCATION. This is not a stock dimension. Quantities
 * stay per (part, location); the ledger, the FIFO costing and stock:reconcile
 * are untouched. Moving five of twelve to another shelf means editing the
 * address, not splitting the count -- because nobody counted that split, and
 * the system should not claim a number it was never told.
 *
 * The unique(stock_balance_id, storage_place_level_id) index is what enforces
 * "one value per level" -- in the index rather than in careful PHP, the same
 * way dppay_att_unique carries the anti-double-pay rule.
 *
 * CAVEAT, restated from the migration this replaces: stock_balances is
 * otherwise a CACHE of the ledger, written only by StockService. The address
 * now hangs off it through a pivot instead of a column on it, which keeps the
 * cache row itself purely derived -- a rebuild of balances no longer risks
 * taking the address with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_place_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['storage_location_id', 'name'], 'storage_place_level_name_unique');
            $table->index(['storage_location_id', 'sort_order'], 'storage_place_level_order_index');
        });

        Schema::create('storage_place_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_place_level_id')->constrained('storage_place_levels')->cascadeOnDelete();
            $table->string('value');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['storage_place_level_id', 'value'], 'storage_place_value_unique');
            $table->index(['storage_place_level_id', 'sort_order'], 'storage_place_value_order_index');
        });

        Schema::create('stock_balance_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_balance_id')->constrained('stock_balances')->cascadeOnDelete();
            $table->foreignId('storage_place_level_id')->constrained('storage_place_levels')->cascadeOnDelete();
            $table->foreignId('storage_place_value_id')->constrained('storage_place_values')->cascadeOnDelete();
            $table->timestamps();

            // One value per level. The whole rule, in one index.
            $table->unique(['stock_balance_id', 'storage_place_level_id'], 'stock_balance_place_unique');
            // "What is on Shelf C?" reads this way round.
            $table->index('storage_place_value_id', 'stock_balance_place_value_index');
        });

        $this->carryOverSlots();

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_slot_id');
        });

        Schema::dropIfExists('storage_slots');
    }

    /**
     * Every slot becomes a value under one level named "Place".
     *
     * As established above, no assignment can exist in practice -- but the
     * NAMES were typed by somebody, and throwing away someone's typing because
     * the feature around it was broken is not our call to make. The assignment
     * loop below is written anyway, so a row set by hand still survives.
     */
    private function carryOverSlots(): void
    {
        if (! Schema::hasTable('storage_slots')) {
            return;
        }

        $slots = DB::table('storage_slots')->whereNull('deleted_at')->orderBy('id')->get();

        if ($slots->isEmpty()) {
            return;
        }

        $now = now();
        /** @var array<int, int> $levelByLocation */
        $levelByLocation = [];
        /** @var array<int, array{level: int, value: int}> $placeBySlot */
        $placeBySlot = [];

        foreach ($slots as $slot) {
            $locationId = (int) $slot->storage_location_id;

            $levelByLocation[$locationId] ??= (int) DB::table('storage_place_levels')->insertGetId([
                'storage_location_id' => $locationId,
                'name' => 'Place',
                'sort_order' => 0,
                'created_by' => $slot->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $placeBySlot[(int) $slot->id] = [
                'level' => $levelByLocation[$locationId],
                'value' => (int) DB::table('storage_place_values')->insertGetId([
                    'storage_place_level_id' => $levelByLocation[$locationId],
                    'value' => $slot->name,
                    'sort_order' => $slot->sort_order ?? 0,
                    'created_by' => $slot->created_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
            ];
        }

        $assigned = DB::table('stock_balances')->whereNotNull('storage_slot_id')->get(['id', 'storage_slot_id']);

        foreach ($assigned as $balance) {
            // A balance pointing at a SOFT-DELETED slot has nothing to carry
            // over, because retired slots were not read above. Skipping is
            // right: that address was already invisible through the API.
            $place = $placeBySlot[(int) $balance->storage_slot_id] ?? null;

            if ($place === null) {
                continue;
            }

            DB::table('stock_balance_places')->insert([
                'stock_balance_id' => $balance->id,
                'storage_place_level_id' => $place['level'],
                'storage_place_value_id' => $place['value'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::create('storage_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['storage_location_id', 'name'], 'storage_slot_name_unique');
            $table->index(['storage_location_id', 'sort_order'], 'storage_slot_order_index');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->foreignId('storage_slot_id')->nullable()->after('storage_location_id')
                ->constrained('storage_slots')->nullOnDelete();
        });

        // Deliberately not repopulating: levels carry strictly more information
        // than slots did, and inventing a single flat name from four levels
        // would be a guess presented as a record.
        Schema::dropIfExists('stock_balance_places');
        Schema::dropIfExists('storage_place_values');
        Schema::dropIfExists('storage_place_levels');
    }
};
