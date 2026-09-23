<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_payment_part_usage', function (Blueprint $table) {
            // Which reimbursable part usages a payment has already counted, and
            // for how much. One PartUsage attaches to many issues, so the
            // unique index below is what stops a single receipt being paid out
            // once per issue it touched.
            $table->id();
            $table->foreignId('daily_pay_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_usage_id')->constrained('part_usages')->cascadeOnDelete();
            $table->foreignId('daily_pay_line_id')->nullable()->constrained('daily_pay_lines')->cascadeOnDelete();

            // Written the long way because stores.id is an externally supplied,
            // non-auto-increment primary key replicated from another service.
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['daily_pay_payment_id', 'part_usage_id'], 'dppay_pu_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_payment_part_usage');
    }
};
