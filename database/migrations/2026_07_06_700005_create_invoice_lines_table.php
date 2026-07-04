<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_lines — a detail record of its parent invoice, same pattern
 * as Phase 2's matter_parties: no own firm_id, tenant isolation flows
 * through invoice_id. time_entry_id is nullable and only set for
 * line_type = time_entry lines; quantity is a decimal DISPLAY value
 * (e.g. hours) derived from the source time_entries.seconds integer —
 * it is never itself the source of truth for billed time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('time_entry_id')->nullable()->constrained('time_entries')->nullOnDelete();

            $table->string('line_type')->default('manual_charge');
            $table->string('description');
            $table->decimal('quantity', 10, 4)->default(1);
            $table->unsignedInteger('rate_cents');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('invoice_id');
            $table->index('time_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
