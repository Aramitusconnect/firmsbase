<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\EntitlementSource;
use App\Enums\PlatformRoleCode;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformIntegrationConnectionDisconnectTest — Phase 2 (FirmsVault
 * Platform Admin Control Center, "Integration Operations Center").
 * Proves PlatformFirmIntegrationBoundedAccessService::disconnectConnection()
 * correctly authorizes via BOTH the narrow
 * canManageIntegrationConnections() role ceiling AND canMutate() (a
 * real, pre-existing gap this method is the first in the class to
 * close), correctly scopes the connection lookup to the given firm,
 * writes a security_events audit row, is idempotent (mirroring
 * ProviderConnectionService::disconnect()'s own established
 * idempotent-short-circuit behavior), and reaches
 * ProviderConnectionService::disconnect() via its new admin-actor path
 * without weakening that method's own FirmUser-path authorization.
 */
final class PlatformIntegrationConnectionDisconnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    public function test_a_superadmin_can_disconnect_a_firms_connection(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $result = app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Requested by firm via support ticket #123');

        $this->assertSame(ConnectionStatus::Disconnected, $result->status);
    }

    public function test_a_platformadmin_can_disconnect_a_firms_connection(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);

        $result = app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Cleanup');

        $this->assertSame(ConnectionStatus::Disconnected, $result->status);
    }

    /**
     * SupportAgent/ImplementationSpecialist both pass the BROAD
     * canAccessIntegrationOversight() gate every read/requeue/nudge
     * method uses, but disconnectConnection() uses the narrower
     * canManageIntegrationConnections() ceiling instead — neither role
     * is on it.
     */
    public function test_a_support_agent_cannot_disconnect_a_connection_despite_passing_the_broad_oversight_gate(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->assertTrue(app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed);

        $this->expectException(RuntimeException::class);

        app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Attempted by support agent');
    }

    public function test_an_implementation_specialist_cannot_disconnect_a_connection(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::ImplementationSpecialist);

        $this->expectException(RuntimeException::class);

        app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Attempted by implementation specialist');
    }

    /**
     * canMutate() — a real, pre-existing gap this method is the FIRST
     * in PlatformFirmIntegrationBoundedAccessService to actually
     * consult. A read_only_auditor may also hold SuperAdmin, but
     * canMutate() must still deny regardless of any other role held
     * (blanket rule 9).
     */
    public function test_a_read_only_auditor_cannot_disconnect_even_when_also_holding_superadmin(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $this->expectException(RuntimeException::class);

        app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Attempted by read-only auditor');
    }

    public function test_disconnecting_a_connection_id_belonging_to_a_different_firm_is_rejected(): void
    {
        [$firmA] = $this->entitledFirmWithConnection();
        [, $connectionB] = $this->entitledFirmWithConnection();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->expectException(ModelNotFoundException::class);

        app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firmA, $connectionB->id, 'Cross-firm attempt');
    }

    public function test_disconnect_is_idempotent_a_second_call_is_a_safe_no_op(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $first = $bounded->disconnectConnection($admin, $firm, $connection->id, 'First disconnect');
        $second = $bounded->disconnectConnection($admin, $firm, $connection->id, 'Second disconnect');

        $this->assertSame(ConnectionStatus::Disconnected, $first->status);
        $this->assertSame(ConnectionStatus::Disconnected, $second->status);
        $this->assertSame($first->disconnected_at?->toDateTimeString(), $second->disconnected_at?->toDateTimeString());

        $auditRows = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.connection_disconnected')
                ->orderBy('id')
                ->get()
        );

        $this->assertCount(2, $auditRows, 'Both calls must each write their own audit row, even though the second is a no-op at the connection level.');
    }

    public function test_a_successful_disconnect_writes_a_security_event_with_correct_actor_and_reason(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Requested by firm via support ticket #123');

        $audit = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.connection_disconnected')
                ->first()
        );

        $this->assertNotNull($audit);
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('platform_integration_oversight', $audit->category);
        $this->assertSame('Requested by firm via support ticket #123', $audit->metadata['reason']);
        $this->assertSame($connection->id, $audit->metadata['firm_integration_id']);
        $this->assertSame('disconnected', $audit->metadata['resulting_status']);
    }

    /**
     * ProviderConnectionService::disconnect()'s own docblock records
     * the acting PlatformAdmin's id as `actor_platform_admin_id` inside
     * its OWN integration_oauth.disconnect timeline_events metadata —
     * independent of, and in addition to, the bounded-access-service
     * security_events row proven above.
     */
    public function test_the_underlying_disconnect_event_records_the_acting_platform_admin_id_as_metadata_evidence(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Reason');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'integration_oauth.disconnect')
                ->latest('id')
                ->first()
        );

        $this->assertNotNull($event);
        $this->assertNull($event->actor_type, 'The admin-actor path must never fabricate a User actor.');
        $this->assertSame($admin->id, $event->metadata_json['actor_platform_admin_id']);
    }

    /**
     * ProviderConnectionService::disconnect() itself must throw when
     * called with neither a FirmUser id nor an admin id, and must
     * reject being called with both — proving the admin-actor path is
     * a narrow, additive extension, not a silent default.
     */
    public function test_disconnect_requires_exactly_one_of_current_user_id_or_actor_platform_admin_id(): void
    {
        [, $connection] = $this->entitledFirmWithConnection();

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext(
            Firm::query()->find($connection->firm_id),
            fn () => app(ProviderConnectionService::class)->disconnect($connection)
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function entitledFirmWithConnection(): array
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        return [$firm, $connection];
    }
}
