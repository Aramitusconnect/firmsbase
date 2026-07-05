<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_subscriptions — the firm-owned root of the Phase 14 webhook
 * foundation (approved data contract, exactly 5 tables). event_types is
 * a json array validated at the application layer against
 * WebhookEventTypeRegistry's 11 approved cases — never validated at the
 * DB layer. No `secret` column here; secret material lives only in
 * webhook_secrets (correction #7/#8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->json('event_types');
            $table->string('destination_url');
            $table->string('status');
            $table->json('retry_policy_json');
            $table->string('last_delivery_status')->nullable();
            $table->foreignId('created_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
