<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_accounts — the firm-owned root of the IOLTA trust foundation.
 * bank_name_reference is free text only — no real bank integration,
 * no Stripe/LawPay/QuickBooks trust movement, anywhere in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('account_name');
            $table->string('bank_name_reference')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('opened_at')->useCurrent();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_accounts');
    }
};
