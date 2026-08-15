<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\FirmEntitlementEvent;
use App\Models\ModuleCatalog;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\Configuration\EntitlementResolutionTraceService;
use App\Services\EntitlementOverrideService;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Mission sections 45–48: temporary vs permanent must be a deliberate
 * choice, an expired override must stop being effective, and revocation
 * must stand an override down without destroying its history.
 *
 * Every guarantee is asserted against the SERVICE, not the form.
 */
class EntitlementOverrideGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementOverrideService $overrides;

    private EntitlementService $entitlements;

    private EntitlementResolutionTraceService $trace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overrides = app(EntitlementOverrideService::class);
        $this->entitlements = app(EntitlementService::class);
        $this->trace = app(EntitlementResolutionTraceService::class);
    }

    public function test_an_override_with_no_end_date_is_refused_without_an_explicit_permanence_acknowledgement(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/permanent until explicitly revoked/i');

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            'No duration chosen',
            $actor,
        );
    }

    public function test_an_acknowledged_permanent_override_is_written(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $entitlement = $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            'Permanent by design',
            $actor,
            permanentAcknowledged: true,
        );

        $this->assertNull($entitlement->ends_at);
    }

    public function test_a_temporary_override_needs_no_permanence_acknowledgement(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $entitlement = $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::FirmOverride,
            true,
            'Temporary pilot',
            $actor,
            now()->addWeek(),
        );

        $this->assertNotNull($entitlement->ends_at);
    }

    public function test_an_end_date_in_the_past_is_refused(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must end in the future/i');

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::FirmOverride,
            true,
            'Already over',
            $actor,
            now()->subDay(),
        );
    }

    public function test_the_audit_row_records_whether_the_override_was_temporary_or_permanent(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            'Permanent by design',
            $actor,
            permanentAcknowledged: true,
        );

        $audit = (new TenantContextService)->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'entitlement_override_set')
            ->latest('id')
            ->first());

        $this->assertSame('permanent_until_revoked', $audit->metadata['override_duration']);
    }

    public function test_revoking_an_override_makes_resolution_fall_back_to_the_plan(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        // Plan says enabled; an admin override disables it.
        $this->entitlements->setForSource($firm, $module->module_code, EntitlementSource::Plan, true);
        $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            false,
            'Temporarily disabled',
            $actor,
            permanentAcknowledged: true,
        );

        $this->assertFalse($this->entitlements->isEnabled($firm->id, $module->module_code));

        $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            'Investigation closed',
            $actor,
        );

        $resolution = $this->entitlements->resolve($firm->id, $module->module_code);

        $this->assertTrue($resolution->enabled, 'revocation must hand control back to the plan');
        $this->assertSame(EntitlementSource::Plan, $resolution->source);
    }

    public function test_revocation_preserves_the_override_row_as_historical_evidence(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $created = $this->overrides->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::FirmOverride,
            true,
            'Pilot',
            $actor,
            permanentAcknowledged: true,
        );

        $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::FirmOverride,
            'Pilot ended',
            $actor,
        );

        // Mission section 47: history is never rewritten away.
        //
        // Asserted INSIDE firm context deliberately: firm_entitlements
        // and firm_entitlement_events both carry FORCE RLS, so a
        // context-less assertDatabaseHas() would report the table as
        // empty whether the row survived or not — it could never
        // distinguish "preserved" from "deleted".
        [$entitlementExists, $eventCount] = (new TenantContextService)->runWithFirmContext($firm, fn (): array => [
            FirmEntitlement::query()->whereKey($created->id)->exists(),
            FirmEntitlementEvent::query()->where('firm_entitlement_id', $created->id)->count(),
        ]);

        $this->assertTrue($entitlementExists, 'revocation must never delete the override row');
        $this->assertGreaterThanOrEqual(2, $eventCount, 'both the grant and the revocation must remain in history');
    }

    public function test_revocation_preserves_the_original_enabled_intent(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, true, 'Pilot', $actor, permanentAcknowledged: true,
        );

        $revoked = $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, 'Pilot ended', $actor,
        );

        // The window ended; what the override SAID is untouched.
        $this->assertTrue($revoked->enabled);
        $this->assertNotNull($revoked->ends_at);
        $this->assertFalse($revoked->isWithinActiveWindow());
    }

    public function test_revoking_a_non_existent_override_is_refused_rather_than_creating_one(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no admin_override override for that module/i');

        $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::AdminOverride, 'nothing to revoke', $actor,
        );
    }

    /**
     * Mission sections 46/122 — the Prompt 4 / Prompt 5 boundary.
     */
    public function test_plan_derived_entitlements_cannot_be_written_through_the_override_service(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only accepts FirmOverride or AdminOverride/i');

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::Plan, true, 'trying to edit the plan', $actor,
            permanentAcknowledged: true,
        );
    }

    public function test_organization_inherited_entitlements_cannot_be_revoked_as_overrides(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only accepts FirmOverride or AdminOverride/i');

        $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::OrgInherited, 'not an override', $actor,
        );
    }

    public function test_revocation_is_audited_with_its_reason(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, true, 'Pilot', $actor, permanentAcknowledged: true,
        );
        $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, 'Pilot concluded', $actor,
        );

        $audit = (new TenantContextService)->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'entitlement_override_revoked')
            ->latest('id')
            ->first());

        $this->assertNotNull($audit);
        $this->assertSame('Pilot concluded', $audit->metadata['reason']);
        $this->assertSame($actor->id, $audit->actor_id);
    }

    public function test_the_trace_reflects_a_revoked_override_as_expired(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->overrides->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, true, 'Pilot', $actor, permanentAcknowledged: true,
        );
        $this->overrides->revokeOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, 'Pilot concluded', $actor,
        );

        $trace = $this->trace->trace($firm, $module->module_code);
        $row = collect($trace['rows'])->firstWhere('source', EntitlementSource::FirmOverride);

        $this->assertSame('Expired', $row['window_state']);
        $this->assertFalse($row['is_winner']);
    }
}
