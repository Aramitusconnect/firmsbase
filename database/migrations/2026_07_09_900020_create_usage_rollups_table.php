<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * usage_rollups — keyed to billing_account_id (project rule 11), with
 * OPTIONAL per-firm attribution via a nullable firm_id. A row with
 * firm_id = null represents the billing-account/organization-level
 * aggregate for that metric/period; a row with firm_id set represents
 * one member firm's contribution to that aggregate. Same reasoning as
 * platform_invoice_lines: firm_id here is attribution, not the tenant
 * ownership boundary, so this table is deliberately EXCLUDED from Phase
 * 6 RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_rollups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('metric');
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->string('unit')->nullable();

            $table->timestamps();

            $table->index('billing_account_id');
            $table->index(['metric', 'period_starts_at']);
            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_rollups');
    }
};
