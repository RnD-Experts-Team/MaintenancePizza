<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_usages', function (Blueprint $table) {
            $table->id();
            // A specific part consumed on a ticket, with its cost (distinct from the parts catalog).
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->decimal('cost', 10, 2);
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_usages');
    }
};
