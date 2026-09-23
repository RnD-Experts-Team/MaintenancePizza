<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            // A physical place parts are kept. There is more than one, so every
            // stock figure is per (part, location) rather than per part.
            $table->id();
            $table->string('name');
            // Optional short code the SPA can show in tight table cells.
            $table->string('code')->nullable()->unique();
            $table->string('address')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
