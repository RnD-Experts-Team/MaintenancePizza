<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments. Replaces Daily Pay sheets and per-ticket pay entries.
 *
 * A PAYMENT is the record of one invoice we paid: when, to whom (exactly one
 * technician OR one supplier), how (cash, check, card...), the invoice number
 * and file. It is not an end-of-day settlement any more.
 *
 * Its LINES split the money by where it was spent: one line per store (or per
 * storage location, for parts bought for the shelf), each with a type:
 *   hourly     clocked time x rate (breaks unpaid);
 *   fixed      one agreed price for that store;
 *   inspection the price of an inspection;
 *   parts      part x quantity x unit cost, `bought_from` a supplier, going
 *              into storage (a Bought stock movement) or straight to a ticket;
 *   gas        gas / mileage;
 *   other      anything else, described in words.
 *
 * Lines link to the ticket issues they paid for, and hourly lines to the trip
 * sessions they paid -- a session can be paid only ONCE (unique index).
 * Technician purchases (parts a technician bought with his own money, from
 * the trip page or the ticket) wait in `technician_purchases` until a parts
 * line pays them back.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->date('paid_at')->index();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('method', 16)->nullable();
            $table->string('reference')->nullable();
            $table->string('invoice_number')->nullable()->index();
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['technician_id', 'paid_at']);
            $table->index(['supplier_id', 'paid_at']);
        });

        Schema::create('payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('type', 16);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->string('other_store')->nullable();
            $table->foreignId('storage_location_id')->nullable()->constrained('storage_locations')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);

            // hourly
            $table->decimal('hours', 8, 2)->nullable();
            $table->decimal('rate', 10, 4)->nullable();
            $table->boolean('hours_overridden')->default(false);

            // parts
            $table->foreignId('part_id')->nullable()->constrained('parts')->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_cost', 10, 4)->nullable();
            $table->foreignId('bought_from_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();

            // gas
            $table->decimal('miles', 10, 2)->nullable();

            // inspection
            $table->foreignId('issue_inspection_id')->nullable()->constrained('issue_inspections')->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payment_id', 'sort_order']);
            $table->index(['type', 'store_id']);
            $table->index('part_id');
        });

        Schema::create('payment_line_ticket_issue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_line_id')->constrained('payment_lines')->cascadeOnDelete();
            $table->foreignId('ticket_issue_id')->constrained('ticket_issues')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['payment_line_id', 'ticket_issue_id'], 'pl_ti_unique');
            $table->index('ticket_issue_id');
        });

        Schema::create('payment_line_trip_session', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_line_id')->constrained('payment_lines')->cascadeOnDelete();
            $table->foreignId('trip_session_id')->unique()->constrained('trip_sessions')->cascadeOnDelete();
            $table->unsignedInteger('paid_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->json('snapshot');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('technician_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->foreignId('trip_stop_id')->nullable()->constrained('trip_stops')->nullOnDelete();
            $table->foreignId('ticket_issue_id')->nullable()->constrained('ticket_issues')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->restrictOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('total_cost', 12, 2);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('serial_number')->nullable();
            $table->dateTime('bought_at');
            $table->foreignId('payment_line_id')->nullable()->constrained('payment_lines')->nullOnDelete();
            $table->boolean('mistaken')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['technician_id', 'payment_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_purchases');
        Schema::dropIfExists('payment_revisions');
        Schema::dropIfExists('payment_line_trip_session');
        Schema::dropIfExists('payment_line_ticket_issue');
        Schema::dropIfExists('payment_lines');
        Schema::dropIfExists('payments');
    }
};
