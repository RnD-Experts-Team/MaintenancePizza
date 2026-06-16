<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_entry_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_pay_entry_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_entry_revisions');
    }
};
