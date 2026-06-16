<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            // Polymorphic owner: ticket, issue, attendance entry, diagnosis, ...
            $table->morphs('notable');
            // Optional category. Ticket final notes use App\Enums\FinalNoteType
            // ('final_notes' | 'what_we_learned'); generic notes leave this null.
            $table->string('type')->nullable();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
