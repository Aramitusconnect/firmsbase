<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_invoice_lines — line items on a platform invoice. firm_id is
 * NULLABLE and exists ONLY for per-firm usage attribution on a
 * consolidated organization invoice (project rule 4: "consolidated
 * invoices must support per-firm usage attribution for AI tokens,
 * storage, and seats"). This is attribution metadata, NOT a tenant
 * ownership boundary — the row's real owner is the platform invoice /
 * billing account, which is why this table is deliberately EXCLUDED
 * from Phase 6 RLS (approved decision): a billing account can span
 * multiple member firms under an organization, so firm-keyed RLS here
 * would incorrectly hide legitimate cross-firm attribution lines from
 * a billing-account-level viewer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('platform_invoice_id')->constrained('platform_invoices')->cascadeOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_cents');
            $table->unsignedBigInteger('amount_cents');
            $table->string('usage_metric')->nullable();

            $table->timestamps();

            $table->index('platform_invoice_id');
            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_lines');
    }
};
