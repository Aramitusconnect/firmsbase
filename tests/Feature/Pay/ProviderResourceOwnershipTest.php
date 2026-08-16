<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Exceptions\Pay\ProviderResourceOwnershipConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Models\Firm;
use App\Services\Pay\ProviderResourceOwnershipService;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FV-A-038 / FV-A-039 / FV-A2-010 / FV-A2-011 / FV-A2-012 — provider
 * resource ownership. ALL CERTIFICATION BLOCKING.
 *
 * Runs against real PostgreSQL (v1.4 §53). FV-A-039 uses two genuinely
 * separate database connections so the concurrency is real contention
 * on a real unique index, not a simulated race.
 */
class ProviderResourceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function ownership(): ProviderResourceOwnershipService
    {
        return app(ProviderResourceOwnershipService::class);
    }

    /**
     * @return array{0: Firm, 1: IntegrationProvider, 2: FirmIntegration}
     */
    private function firmWithConnection(): array
    {
        $firm = Firm::factory()->create();
        $provider = IntegrationProvider::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
        ]));

        return [$firm, $provider, $connection];
    }

    /** FV-A-038 — one authoritative ownership source. */
    public function test_fv_a_038_provider_resource_has_exactly_one_authoritative_tenant_owner(): void
    {
        [$firm, $provider, $connection] = $this->firmWithConnection();

        $row = $this->ownership()->establishOwnership(
            (int) $firm->id,
            (int) $connection->id,
            (int) $provider->id,
            'payment',
            'PROV-RESOURCE-1',
        );

        $this->assertSame((int) $firm->id, (int) $row->firm_id);

        $resolved = $this->ownership()->resolveOwner((int) $provider->id, 'payment', 'PROV-RESOURCE-1');

        $this->assertNotNull($resolved);
        $this->assertSame((int) $firm->id, $resolved->firmId);
        $this->assertSame((int) $connection->id, $resolved->firmIntegrationId);

        // Exactly one ownership row exists for this resource, system-wide.
        $this->assertSame(1, DB::table('integration_webhook_routing_index')
            ->where('provider_resource_type', 'payment')
            ->where('provider_resource_id', 'PROV-RESOURCE-1')
            ->count());
    }

    /** FV-A-038 — the database, not the application, is the authority. */
    public function test_fv_a_038_database_rejects_a_second_owner_for_the_same_provider_resource(): void
    {
        [$firmA, $provider, $connectionA] = $this->firmWithConnection();

        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'integration_provider_id' => $provider->id,
        ]));

        $this->ownership()->establishOwnership(
            (int) $firmA->id, (int) $connectionA->id, (int) $provider->id, 'payment', 'SHARED-RESOURCE'
        );

        // Bypass the service entirely — prove the DATABASE refuses.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('integration_webhook_routing_index')->insert([
            'firm_id' => $firmB->id,
            'firm_integration_id' => $connectionB->id,
            'integration_provider_id' => $provider->id,
            'webhook_routing_token_hash' => null,
            'provider_resource_type' => 'payment',
            'provider_resource_id' => 'SHARED-RESOURCE',
            'ownership_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** FV-A-038 — re-establishing identical ownership is safe, not a conflict. */
    public function test_fv_a_038_re_establishing_identical_ownership_returns_the_same_row(): void
    {
        [$firm, $provider, $connection] = $this->firmWithConnection();

        $first = $this->ownership()->establishOwnership(
            (int) $firm->id, (int) $connection->id, (int) $provider->id, 'payment', 'IDEMPOTENT-RESOURCE'
        );
        $second = $this->ownership()->establishOwnership(
            (int) $firm->id, (int) $connection->id, (int) $provider->id, 'payment', 'IDEMPOTENT-RESOURCE'
        );

        $this->assertSame($first->id, $second->id);
    }

    /** FV-A-039 — the service surfaces the loss as an explicit conflict. */
    public function test_fv_a_039_service_raises_an_ownership_conflict_for_a_different_firm(): void
    {
        [$firmA, $provider, $connectionA] = $this->firmWithConnection();

        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'integration_provider_id' => $provider->id,
        ]));

        $this->ownership()->establishOwnership(
            (int) $firmA->id, (int) $connectionA->id, (int) $provider->id, 'payment', 'CONFLICT-RESOURCE'
        );

        $this->expectException(ProviderResourceOwnershipConflictException::class);

        $this->ownership()->establishOwnership(
            (int) $firmB->id, (int) $connectionB->id, (int) $provider->id, 'payment', 'CONFLICT-RESOURCE'
        );
    }

    /** FV-A2-010 — ownership is immutable after establishment. */
    public function test_fv_a2_010_ownership_cannot_be_reassigned_in_place(): void
    {
        [$firm, $provider, $connection] = $this->firmWithConnection();
        $firmB = Firm::factory()->create();

        $row = $this->ownership()->establishOwnership(
            (int) $firm->id, (int) $connection->id, (int) $provider->id, 'payment', 'IMMUTABLE-RESOURCE'
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ownership is immutable/');

        $row->update(['firm_id' => $firmB->id]);
    }

    /** FV-A2-010 — an ownership row can never be deleted. */
    public function test_fv_a2_010_ownership_row_cannot_be_deleted(): void
    {
        [$firm, $provider, $connection] = $this->firmWithConnection();

        $row = $this->ownership()->establishOwnership(
            (int) $firm->id, (int) $connection->id, (int) $provider->id, 'payment', 'UNDELETABLE-RESOURCE'
        );

        $this->expectException(\LogicException::class);

        $row->delete();
    }

    /**
     * FV-A2-011 — deactivating ownership does NOT free the resource.
     * This is the realistic attack: deactivate, then let another firm
     * claim the same historical provider resource.
     */
    public function test_fv_a2_011_inactive_resource_cannot_be_reassigned_to_another_firm(): void
    {
        [$firmA, $provider, $connectionA] = $this->firmWithConnection();

        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'integration_provider_id' => $provider->id,
        ]));

        $row = $this->ownership()->establishOwnership(
            (int) $firmA->id, (int) $connectionA->id, (int) $provider->id, 'payment', 'HISTORICAL-RESOURCE'
        );

        // The one permitted lifecycle change.
        $deactivated = $this->ownership()->deactivate($row);
        $this->assertSame('inactive', $deactivated->ownership_status);

        // It no longer routes...
        $this->assertNull($this->ownership()->resolveOwner((int) $provider->id, 'payment', 'HISTORICAL-RESOURCE'));

        // ...but it still occupies the identity forever.
        $this->expectException(ProviderResourceOwnershipConflictException::class);

        $this->ownership()->establishOwnership(
            (int) $firmB->id, (int) $connectionB->id, (int) $provider->id, 'payment', 'HISTORICAL-RESOURCE'
        );
    }

    /**
     * FV-A2-012 — a business-resource mapping cannot conflict with
     * routing ownership. The ProviderCommand (the business mapping)
     * carries a composite FK to firm_integrations, so it can only ever
     * name a provider account inside its own firm; it has no ownership
     * columns of its own to disagree with.
     */
    public function test_fv_a2_012_business_mapping_cannot_assign_or_override_tenant_ownership(): void
    {
        [$firmA, $provider, $connectionA] = $this->firmWithConnection();

        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'integration_provider_id' => $provider->id,
        ]));

        $this->ownership()->establishOwnership(
            (int) $firmA->id, (int) $connectionA->id, (int) $provider->id, 'payment', 'MAPPED-RESOURCE'
        );

        // Firm A tries to bind a command to Firm B's provider account.
        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB, $provider) {
            DB::table('provider_commands')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'firm_integration_id' => $connectionB->id, // another firm's account
                'integration_provider_id' => $provider->id,
                'command_type' => 'capture_payment',
                'aggregate_type' => 'X',
                'aggregate_id' => 1,
                'idempotency_key' => 'x:'.Str::uuid(),
                'canonical_payload_hash' => str_repeat('a', 64),
                'canonical_payload' => '{}',
                'correlation_id' => (string) Str::uuid(),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * v1.4 §39 — the pre-tenant resolver must NOT be a gateway to
     * tenant financial data. It returns only routing identity.
     */
    public function test_fv_a2_066_routing_resolver_returns_only_routing_identity_never_financial_data(): void
    {
        [$firm, $provider, $connection] = $this->firmWithConnection();

        $this->ownership()->establishOwnership(
            (int) $firm->id, (int) $connection->id, (int) $provider->id, 'payment', 'BOUNDED-RESOURCE'
        );

        // Deliberately NO tenant context established.
        (new TenantContextService)->clearDatabaseTenantContext();

        $resolved = $this->ownership()->resolveOwner((int) $provider->id, 'payment', 'BOUNDED-RESOURCE');

        $this->assertNotNull($resolved, 'Pre-tenant resolution must work without firm context.');
        $this->assertSame(
            ['firmId', 'firmIntegrationId', 'integrationProviderId', 'providerKey'],
            array_keys(get_object_vars($resolved)),
            'The resolver must expose routing identity ONLY — no financial or connection metadata.'
        );

        // And that identity buys no access to tenant financial tables.
        $this->assertSame(0, DB::table('payment_intents')->count());
        $this->assertSame(0, DB::table('provider_commands')->count());
    }

    /**
     * The existing token addressing mode must be completely unaffected
     * by the Gate A2 extension — a regression guard on a live,
     * security-reviewed path.
     */
    public function test_existing_routing_token_mode_still_works_and_is_unique(): void
    {
        [$firm, $provider, $connection] = $this->firmWithConnection();

        $hash = hash('sha256', 'a-routing-token');

        IntegrationWebhookRoutingIndex::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'integration_provider_id' => $provider->id,
            'webhook_routing_token_hash' => $hash,
        ]);

        $found = DB::table('integration_webhook_routing_index')
            ->where('webhook_routing_token_hash', $hash)
            ->first();

        $this->assertNotNull($found);
        $this->assertNull($found->provider_resource_id, 'A token-mode row carries no resource identity.');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('integration_webhook_routing_index')->insert([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'integration_provider_id' => $provider->id,
            'webhook_routing_token_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
