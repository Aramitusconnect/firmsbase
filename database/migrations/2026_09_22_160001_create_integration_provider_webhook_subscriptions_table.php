<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_provider_webhook_subscriptions — FirmsVault Live
 * Integrations, Checkpoint 2 (checkpoint2-design-sync-webhooks.md §3.2,
 * "A genuine new persistence gap: there is nowhere to durably store a
 * real subscription's state"; checkpoint2-combined-design.md §2 P-17).
 * Direct firm-owned, same composite-FK/index shape as
 * integration_sync_cursors' own create migration (this checkpoint's
 * chosen template pair).
 *
 * `subscribe()`/`renewSubscription()` (SupportsWebhooksContract) return
 * only an opaque `array<string, mixed>` describing a remote provider
 * subscription (id, expiry) — no table anywhere in this codebase
 * persisted that state before this migration (grepped: zero production
 * call sites for either method existed prior to this checkpoint). This
 * table is the durable home for it. No secret material lives here —
 * Graph's separate short-lived subscription access token (which
 * authorizes GRAPH's own outbound notification delivery to FirmsVault,
 * never the reverse) is not a FirmsVault bearer credential and is never
 * stored anywhere.
 *
 * Naturally provider-agnostic despite only Microsoft needing it built
 * right now (design's own framing): Google's `watch()` push channels
 * have the identical "remote subscription with an expiry that must be
 * renewed" shape, so `provider_key`/`provider_resource`/
 * `provider_change_type` are deliberately plain, unconstrained strings
 * (provider's own vocabulary, never FirmsVault's ResourceType string),
 * mirroring `integration_inbound_webhook_events.provider_key`'s own
 * precedent — not an FK to any closed catalog.
 *
 * `status` is a plain string column (no DB-level enum type), backed by
 * the application-level App\Integrations\Enums\ProviderWebhookSubscriptionStatus
 * enum on the model — mirrors CursorStatus's identical
 * plain-string-column/app-level-enum shape on integration_sync_cursors.
 *
 * Idempotency (design §3.1 "subscribe() should be idempotent from
 * FirmsVault's own side first"): UNIQUE(firm_integration_id,
 * provider_resource, provider_change_type) WHERE status = 'active' —
 * only one currently-active subscription may exist per connection per
 * (provider_resource, provider_change_type) scope. Declared via raw SQL
 * below (Laravel's fluent unique() cannot express a partial WHERE
 * clause) — same convention integration_conflicts'
 * integration_conflicts_one_open_per_local_record index already
 * establishes in this codebase; a write path that needs the atomic
 * `INSERT ... ON CONFLICT (...) WHERE status = 'active' DO NOTHING`
 * form must repeat this exact predicate, never the fluent
 * insertOrIgnoreReturning()/upsert() uniqueBy shape.
 *
 * `last_renewal_error` — nullable, sanitized-category-only (one of
 * SanitizedProviderHttpException's closed category strings), never raw
 * provider response text — same discipline every other
 * *_error/*_diagnostic column in this domain already follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_provider_webhook_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('provider_key');
            $table->string('resource_type');
            $table->string('provider_resource');
            $table->string('provider_change_type');
            $table->string('provider_subscription_id');

            $table->timestamp('expires_at');
            $table->string('status')->default('active');

            $table->timestamp('last_renewed_at')->nullable();
            $table->string('last_renewal_error')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['status', 'expires_at']);

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_provider_webhook_subscriptions_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX integration_provider_webhook_subscriptions_one_active_per_resource '.
            'ON integration_provider_webhook_subscriptions (firm_integration_id, provider_resource, provider_change_type) '.
            "WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_provider_webhook_subscriptions');
    }
};
