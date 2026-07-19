<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEvent;
use App\Models\WebhookSecret;
use App\Models\WebhookSubscription;
use App\Services\TenantSafeWebhookPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * Defense-in-depth cross-firm isolation for all 5 Wave 11 (Phase 14)
 * tables, via TenantSafeWebhookPolicyService, independent of
 * BelongsToTenant's global scope (webhook_deliveries/webhook_secrets/
 * webhook_delivery_attempts don't use that trait at all — see model
 * docblocks).
 *
 * Every table in this domain now has permanent FORCE ROW LEVEL
 * SECURITY active (database/migrations/2026_08_31_990001 through
 * 990005), so every factory ->create() call below must run under the
 * row's own firm's tenant context (a bare create with no context would
 * fail WITH CHECK entirely) — matching every other FORCE-RLS'd table's
 * established test convention in this rollout.
 *
 * Every helper below explicitly creates and passes a
 * `created_by_firm_user_id` owner (rather than letting
 * WebhookSubscriptionFactory's default `FirmUser::factory()` nested
 * relationship run) because FirmUserFactory has its own FORCE-RLS
 * context-hold create() override that deliberately LEAVES the
 * PostgreSQL session's app.current_firm_id set to whatever firm_id it
 * resolved (see FirmUserFactory's own docblock) — an unrelated,
 * randomly-generated nested Firm/FirmUser pair would silently
 * clobber this test's own outer firm context for the remainder of the
 * enclosing transaction, causing the subsequent webhook_subscriptions
 * insert to fail its own WITH CHECK. Passing an explicit
 * created_by_firm_user_id for the SAME firm avoids invoking that
 * nested default entirely.
 */
class WebhookTenantIsolationTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    private TenantSafeWebhookPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TenantSafeWebhookPolicyService::class);
    }

    protected function tearDown(): void
    {
        \App\Services\TenantContextResolver::clear();

        parent::tearDown();
    }

    public function test_webhook_subscription_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $subscriptionA = $this->createSubscriptionForFirm($firmA);

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookSubscriptionBelongsToFirm($subscriptionA, $firmB);
    }

    public function test_webhook_event_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $eventA = $this->runWithFirmContext($firmA, fn () => WebhookEvent::factory()->forFirm($firmA)->create());

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookEventBelongsToFirm($eventA, $firmB);
    }

    public function test_webhook_delivery_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $deliveryA = $this->createDeliveryForFirm($firmA);

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookDeliveryBelongsToFirm($deliveryA, $firmB);
    }

    public function test_webhook_secret_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $secretA = $this->createSecretForFirm($firmA);

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookSecretBelongsToFirm($secretA, $firmB);
    }

    /**
     * Companion test required by Wave 11 Phase 3's design (section 1.5):
     * webhook_delivery_attempts was the sole hybrid-ownership table in
     * this domain missing a TenantSafeWebhookPolicyService assertion,
     * despite carrying the strictest immutability guarantees in the
     * domain. Mirrors the four sibling tests above exactly.
     */
    public function test_webhook_delivery_attempt_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $attemptA = $this->createDeliveryAttemptForFirm($firmA);

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookDeliveryAttemptBelongsToFirm($attemptA, $firmB);
    }

    public function test_belongs_to_tenant_global_scope_hides_another_firms_subscriptions(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $subscriptionA = $this->createSubscriptionForFirm($firmA);
        $subscriptionB = $this->createSubscriptionForFirm($firmB);

        // Both the PHP-memory (BelongsToTenant global scope) and the
        // PostgreSQL session (FORCE RLS) layers must be active for this
        // read to see any rows at all now that webhook_subscriptions is
        // permanently FORCE RLS'd — a bare TenantContextResolver
        // activation alone is no longer sufficient.
        $visibleIds = $this->runWithFirmContext($firmA, fn () => WebhookSubscription::query()->pluck('id')->all());

        $this->assertContains($subscriptionA->id, $visibleIds);
        $this->assertNotContains($subscriptionB->id, $visibleIds);
    }

    /**
     * webhook_events also uses BelongsToTenant (unlike webhook_deliveries/
     * webhook_secrets/webhook_delivery_attempts) — proves the app-layer
     * global scope independently hides another firm's events, the same
     * defense-in-depth guarantee proven for subscriptions above.
     */
    public function test_belongs_to_tenant_global_scope_hides_another_firms_events(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $eventA = $this->runWithFirmContext($firmA, fn () => WebhookEvent::factory()->forFirm($firmA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => WebhookEvent::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => WebhookEvent::query()->pluck('id')->all());

        $this->assertContains($eventA->id, $visibleIds);
        $this->assertNotContains($eventB->id, $visibleIds);
    }

    // ---------------------------------------------------------------
    // FORCE-RLS-specific direct-SQL cross-firm-denial assertions for
    // the 4 tables that do NOT use BelongsToTenant (so the only
    // enforcement mechanism available to them is RLS itself, proven
    // here via raw DB::table() queries under one firm's own context)
    // ---------------------------------------------------------------

    public function test_rls_alone_hides_another_firms_webhook_secret_via_raw_query(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $secretA = $this->createSecretForFirm($firmA);
        $secretB = $this->createSecretForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_secrets')->pluck('id')->all());

        $this->assertContains($secretA->id, $visibleIds);
        $this->assertNotContains($secretB->id, $visibleIds);
    }

    public function test_rls_alone_hides_another_firms_webhook_delivery_via_raw_query(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $deliveryA = $this->createDeliveryForFirm($firmA);
        $deliveryB = $this->createDeliveryForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_deliveries')->pluck('id')->all());

        $this->assertContains($deliveryA->id, $visibleIds);
        $this->assertNotContains($deliveryB->id, $visibleIds);
    }

    public function test_rls_alone_hides_another_firms_webhook_delivery_attempt_via_raw_query(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $attemptA = $this->createDeliveryAttemptForFirm($firmA);
        $attemptB = $this->createDeliveryAttemptForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_delivery_attempts')->pluck('id')->all());

        $this->assertContains($attemptA->id, $visibleIds);
        $this->assertNotContains($attemptB->id, $visibleIds);
    }

    /**
     * webhook_deliveries has no BelongsToTenant global scope, but the
     * defense-in-depth test above already proves RLS alone isolates
     * reads. This proves the equivalent for a cross-firm raw UPDATE
     * attempt, mirroring this rollout's TrustLedgerEntry precedent.
     */
    public function test_firm_a_cannot_update_firm_b_webhook_delivery_via_raw_query(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $deliveryB = $this->createDeliveryForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($deliveryB) {
            return DB::table('webhook_deliveries')->where('id', $deliveryB->id)->update(['attempt_count' => 99]);
        });

        $this->assertSame(0, $affected);
    }

    private function createSubscriptionForFirm(Firm $firm): WebhookSubscription
    {
        $owner = $this->makeFirmOwner($firm);

        return $this->runWithFirmContext($firm, fn () => WebhookSubscription::factory()->forFirm($firm)->create([
            'created_by_firm_user_id' => $owner->id,
        ]));
    }

    private function createSecretForFirm(Firm $firm): WebhookSecret
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $subscription = $this->createSubscriptionForFirmInsideContext($firm);

            // makeWebhookEntitledFirm() already provisioned an active
            // TenantEncryptionKey for $firm — reuse its real id
            // explicitly rather than letting WebhookSecretFactory's
            // default TenantEncryptionKey::factory() nested relationship
            // run, which would create an unrelated random firm's key and
            // (via TenantEncryptionKeyFactory's own context-hold create()
            // override) clobber this test's own outer firm context.
            $encryptionKeyId = $firm->activeTenantEncryptionKey->id;

            return WebhookSecret::factory()->forSubscription($subscription)->create([
                'encryption_key_id' => $encryptionKeyId,
            ]);
        });
    }

    private function createDeliveryForFirm(Firm $firm): WebhookDelivery
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $subscription = $this->createSubscriptionForFirmInsideContext($firm);
            $event = WebhookEvent::factory()->forFirm($firm)->create();

            return WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();
        });
    }

    private function createDeliveryAttemptForFirm(Firm $firm): WebhookDeliveryAttempt
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $delivery = $this->createDeliveryForFirmInsideContext($firm);

            return WebhookDeliveryAttempt::factory()->forDelivery($delivery)->create();
        });
    }

    /**
     * Same as createDeliveryForFirm() but callable from inside an
     * already-active runWithFirmContext() (avoids nesting a second
     * context activation inside createDeliveryAttemptForFirm()).
     */
    private function createDeliveryForFirmInsideContext(Firm $firm): WebhookDelivery
    {
        $subscription = $this->createSubscriptionForFirmInsideContext($firm);
        $event = WebhookEvent::factory()->forFirm($firm)->create();

        return WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();
    }

    /**
     * Creates a WebhookSubscription for $firm, explicitly supplying its
     * own owner FirmUser rather than relying on the factory's default
     * nested FirmUser::factory() — callable from inside an
     * already-active runWithFirmContext() for the same firm (the
     * owner-creation itself is safe to run inside that same context
     * since FirmUserFactory's own context-hold override resolves to
     * this identical firm_id).
     */
    private function createSubscriptionForFirmInsideContext(Firm $firm): WebhookSubscription
    {
        $owner = $this->makeFirmOwner($firm);

        return WebhookSubscription::factory()->forFirm($firm)->create([
            'created_by_firm_user_id' => $owner->id,
        ]);
    }
}
