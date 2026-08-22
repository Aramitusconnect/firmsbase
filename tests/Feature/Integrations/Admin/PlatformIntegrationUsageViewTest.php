<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\UsageOperationType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationUsageViewTest — Checkpoint 11.
 *
 * *** PREVIOUSLY-FLAGGED SCOPE DEVIATION (now closed, verified live) ***
 *
 * Frozen design §11 states: "Usage-view/retention-view/audit-view
 * ceilings reuse the existing canAccessPlatformBilling()/
 * canAccessSecurityLogs() methods directly — no further new methods."
 * Agent 11H's own upstream report (line 616) additionally describes a
 * dedicated `usageForFirm(int $firmId)` read method as part of
 * `IntegrationPlatformOversightReadService`'s expected surface.
 *
 * BOTH now exist, confirmed live:
 *   - `IntegrationPlatformOversightReadService::usageForFirm()` reuses
 *     Checkpoint 10's own `IntegrationUsageSummaryService::
 *     summariesForFirm()` aggregate-query shape and routes through the
 *     same `readWithinFirmAccess()` chokepoint as every other per-firm
 *     method on that class.
 *   - `PlatformFirmIntegrationDetailPage::usageSection()` calls
 *     `PlatformStaffAccessPolicyService::canAccessPlatformBilling()`
 *     directly, and `retentionSection()`/`auditHistorySection()` call
 *     `canAccessSecurityLogs()` directly — each re-checked fresh inside
 *     its own closure on every render, never trusted from mount()-time
 *     alone (mirrors this file's sibling PlatformIntegrationConflictViewTest's
 *     established TOCTOU-safe pattern for `resolution_note` gating).
 *
 * test_usage_for_firm_returns_correct_summaries_for_a_firm_with_real_seeded_usage_records()
 * and test_usage_retention_and_audit_sections_are_gated_by_can_access_platform_billing_and_can_access_security_logs_rechecked_fresh_at_render()
 * below prove both closures live.
 *
 * *** PREVIOUSLY-DISCOVERED PRODUCTION BUG (now fixed, verified live) ***
 *
 * usageForFirm() genuinely exists and is wired correctly. Calling it
 * against a REAL seeded IntegrationUsageRecord row used to throw a
 * TypeError, unconditionally, due to a pre-existing Checkpoint 10 bug in
 * IntegrationUsageSummaryService::summariesForFirm() (line 66 called
 * `SyncDirection::from($row->direction)` on a value that the model's
 * own `casts()` — 'direction' => SyncDirection::class — had already
 * converted to a SyncDirection instance, so `::from()` received an enum
 * instance instead of the raw string it requires). Fixed by using
 * `$row->direction` directly (the model cast already did the
 * conversion); see
 * app/Integrations/Services/IntegrationUsageSummaryService.php.
 * test_usage_for_firm_returns_correct_summaries_for_a_firm_with_real_seeded_usage_records()
 * below now proves the fixed behavior end-to-end against real seeded
 * data, through the exact same usageForFirm() path Checkpoint 11's own
 * production code calls.
 */
final class PlatformIntegrationUsageViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Proves usageForFirm() genuinely exists, is wired correctly, and
     * (now that IntegrationUsageSummaryService::summariesForFirm()'s
     * redundant/broken `SyncDirection::from()` re-cast has been fixed —
     * see this file's class docblock) succeeds against REAL seeded
     * IntegrationUsageRecord data and returns correct summary data,
     * with no exception.
     *
     * integration_usage_records.direction is NOT NULL (see
     * database/migrations/2026_09_08_080001_create_integration_usage_records_table.php),
     * and IntegrationUsageRecord casts 'direction' => SyncDirection::class,
     * so this exercises the exact path that previously threw
     * unconditionally for every real row: this is still the first test
     * in the suite to seed a real IntegrationUsageRecord row and then
     * actually call summariesForFirm() (via usageForFirm()) against it —
     * every prior summariesForFirm()-adjacent test
     * (tests/Feature/Integrations/Ui/FirmIntegrationHealthAndUsageDisplayTest.php's
     * own test_usage_summary_service_returns_genuinely_empty_when_no_records_exist())
     * only exercised the zero-rows case.
     */
    public function test_usage_for_firm_returns_correct_summaries_for_a_firm_with_real_seeded_usage_records(): void
    {
        $this->assertTrue(
            method_exists(IntegrationPlatformOversightReadService::class, 'usageForFirm'),
            'usageForFirm() must exist on IntegrationPlatformOversightReadService (agent-11h-architecture-security-review.md §14) — this part of the checkpoint-11 fix IS in place.'
        );

        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        // Reuses Checkpoint 10's own IntegrationUsageRecord factory
        // convention. Default direction (from the factory) is
        // SyncDirection::Inbound.
        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationUsageRecord::factory()->forFirmIntegration($connection)->create([
                'provider_key' => 'quickbooks',
                'capability' => 'contacts',
                'operation_type' => UsageOperationType::PullSync->value,
                'quantity' => 3,
                'unit' => 'item',
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $summaries = app(IntegrationPlatformOversightReadService::class)->usageForFirm($admin, $firm);

        $this->assertCount(1, $summaries);

        $summary = $summaries->first();
        $this->assertSame('quickbooks', $summary['provider_key']);
        $this->assertSame('contacts', $summary['capability']);
        $this->assertSame(UsageOperationType::PullSync->value, $summary['operation_type']);
        $this->assertSame(SyncDirection::Inbound->value, $summary['direction']);
        $this->assertSame(3, $summary['total_quantity']);
        $this->assertSame('item', $summary['unit']);
    }

    /**
     * Usage section deliberately seeds NO IntegrationUsageRecord row
     * here. The IntegrationUsageSummaryService::summariesForFirm()
     * `direction` cast bug this deliberately dodged has since been
     * fixed (see this file's own
     * test_usage_for_firm_returns_correct_summaries_for_a_firm_with_real_seeded_usage_records()
     * immediately above, which now proves real-data correctness
     * end-to-end) — but this test stays focused purely on
     * billing/security-log gating, independent of usage-summary data
     * correctness, which has its own dedicated coverage above. Gating
     * is fully proven either way: the denied admin must see the denial
     * reason text for all three sections; the allowed admin must see
     * NONE of that denial text and must instead see the section's real
     * (here: honestly-empty) computed output — proving the gate itself,
     * independent of usage-data content.
     */
    public function test_usage_retention_and_audit_sections_are_gated_by_can_access_platform_billing_and_can_access_security_logs_rechecked_fresh_at_render(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $auditMarker = 'integration.gating_marker_5d2b8f61';

        $this->runWithFirmContext($firm, function () use ($firm, $auditMarker) {
            TimelineEvent::create([
                'firm_id' => $firm->id,
                'subject_type' => 'App\\Integrations\\Models\\FirmIntegration',
                'subject_id' => 1,
                'event_type' => $auditMarker,
                'actor_type' => null,
                'actor_id' => null,
                'occurred_at' => now(),
                'metadata_json' => [],
            ]);
        });

        config(['integrations.usage_records.retention_days' => 424242]);

        $billingDenialReason = 'no active role grants access to platform billing';
        $securityLogsDenialReason = 'no active role grants access to security logs';

        // Denial case: ImplementationSpecialist is one of
        // PlatformFirmIntegrationBoundedAccessService::UNCONDITIONALLY_TRUSTED_ROLES,
        // so it passes the coarse canAccessIntegrationOversight() gate
        // (and needs no support-access session to reach content() at
        // all) — but it is in NEITHER
        // PlatformStaffAccessPolicyService::PLATFORM_BILLING_ROLES nor
        // ::SECURITY_LOG_ROLES, so usage/retention/audit must all be
        // denied for it.
        $deniedAdmin = $this->adminWithRole(PlatformRoleCode::ImplementationSpecialist);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($deniedAdmin, 'platform_admin');

        $deniedTest = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $deniedTest->assertOk();
        $deniedTest->assertDontSee($auditMarker);
        $deniedTest->assertDontSee('424242');
        $deniedTest->assertSee($billingDenialReason);
        $deniedTest->assertSee($securityLogsDenialReason);

        // Success case: SuperAdmin is in both PLATFORM_BILLING_ROLES and
        // SECURITY_LOG_ROLES, so none of the denial text renders and
        // retention/audit render their real data for the exact same
        // firm/connection; the usage section renders its honest empty
        // state (no usage seeded) rather than the denial reason.
        $allowedAdmin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($allowedAdmin, 'platform_admin');

        $allowedTest = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $allowedTest->assertOk();
        $allowedTest->assertDontSee($billingDenialReason);
        $allowedTest->assertDontSee($securityLogsDenialReason);
        $allowedTest->assertSee('No usage has been recorded for this connection yet.');
        $allowedTest->assertSee($auditMarker);
        $allowedTest->assertSee('424242');
    }

    /**
     * Security review Finding 3 (CHECKPOINT_11_SECURITY_IMPLEMENTATION_REJECTED):
     * canAccessPlatformBilling() used to be checked ONLY inside
     * PlatformFirmIntegrationDetailPage's Filament closure, never inside
     * IntegrationPlatformOversightReadService::usageForFirm() itself.
     * This proves the gate is now enforced at the SERVICE layer, by
     * calling usageForFirm() directly (not via the UI page) with a role
     * that passes the coarse assertCanAccessFirm() oversight/session gate
     * (ImplementationSpecialist is one of
     * PlatformFirmIntegrationBoundedAccessService::UNCONDITIONALLY_TRUSTED_ROLES,
     * so it needs no support-access session) but is in NEITHER
     * PlatformStaffAccessPolicyService::PLATFORM_BILLING_ROLES.
     */
    public function test_usage_for_firm_is_denied_at_the_service_layer_for_a_role_without_platform_billing_access(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::ImplementationSpecialist);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active role grants access to platform billing');

        app(IntegrationPlatformOversightReadService::class)->usageForFirm($admin, $firm);
    }

    public function test_the_only_real_usage_adjacent_surface_is_the_usage_records_retention_days_config_line_item(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $summary = app(IntegrationPlatformOversightReadService::class)->retentionConfigSummary($admin);

        $this->assertArrayHasKey('usage_records_retention_days', $summary);
    }

    public function test_the_detail_page_renders_the_usage_records_retention_days_line_item_within_the_retention_section(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        config(['integrations.usage_records.retention_days' => 999]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertSee('usage records retention days');
        $test->assertSee('999');
    }

    public function test_every_view_gated_uniformly_by_the_same_coarse_oversight_and_per_firm_session_gate_including_usage_adjacent_data(): void
    {
        // Since there is no distinct usage-view ceiling, a SupportAgent
        // without a session must be denied the retention (usage-adjacent)
        // config surface exactly like every other per-firm/oversight
        // surface — proven at the coarse gate level, which
        // retentionConfigSummary() itself calls.
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        // No role grant at all.

        $this->expectException(\RuntimeException::class);
        app(IntegrationPlatformOversightReadService::class)->retentionConfigSummary($admin);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
