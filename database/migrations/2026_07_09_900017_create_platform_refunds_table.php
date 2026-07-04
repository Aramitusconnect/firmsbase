<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_refunds — refunds against a platform_payments row.
 * Deliberately separate from any Phase 3 firm-client refund concept
 * (project rule 1/8) — this table only ever references
 * platform_payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('platform_payment_id')->constrained('platform_payments')->cascadeOnDelete();

            $table->string('status')->default('requested');
            $table->unsignedBigInteger('amount_cents');
            $table->text('reason')->nullable();
            $table->string('gateway_refund_ref')->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('platform_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_refunds');
    }
};
