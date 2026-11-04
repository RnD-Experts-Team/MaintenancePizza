<?php

use App\Support\NameKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parts get the details people actually look for on a shelf -- brand, model,
 * part number, the unit they are counted in -- and a switch for the parts
 * worth tracking one by one: with `track_serials` on, every unit is its own
 * row in `part_units` (serial number, where it is, which store it went to);
 * with it off the part is counted by quantity, as before.
 *
 * Suppliers are the places we buy from (Walmart, Amazon, Home Depot). They are
 * not technicians: they are never booked, rated or clocked, only paid or
 * named as where a part was bought. Names are unique the same way technician
 * names are.
 *
 * Technician categories are an editable list; the three the business uses are
 * seeded if a category of that name does not exist yet.
 */
return new class extends Migration {
    private const DEFAULT_CATEGORIES = [
        'In-house' => 'Our own maintenance team.',
        'Company' => 'A company we hire (an existing vendor).',
        'Independent' => 'An independent technician working for himself.',
    ];

    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('description');
            $table->string('model')->nullable()->after('brand');
            $table->string('part_number')->nullable()->after('model')->index();
            $table->string('unit', 32)->default('each')->after('part_number');
            $table->boolean('track_serials')->default(false)->after('unit');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (self::DEFAULT_CATEGORIES as $name => $description) {
            if (DB::table('categories')->where('name_key', NameKey::of($name))->exists()) {
                continue;
            }

            DB::table('categories')->insert([
                'name' => $name,
                'name_key' => NameKey::of($name),
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');

        Schema::table('parts', function (Blueprint $table) {
            $table->dropIndex(['part_number']);
            $table->dropColumn(['brand', 'model', 'part_number', 'unit', 'track_serials']);
        });
    }
};
