<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use App\Services\PrePilotRemediationBacklogService;
use Tests\TestCase;

class PrePilotUiBuildReadinessTest extends TestCase
{
    private PrePilotRemediationBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrePilotRemediationBacklogService();
    }

    public function test_safe_first_pilot_ui_scope_excludes_blocked_modules(): void
    {
        $unsafeModules = array_filter($this->service->safeFirstPilotUiScope(), fn (array $m) => $m['safe'] === false);
        $unsafeModuleNames = array_column($unsafeModules, 'module');

        foreach (['client_portal_mobile', 'trust_iolta_ui', 'ai_ui'] as $blockedModule) {
            $this->assertContains($blockedModule, $unsafeModuleNames, "Module '{$blockedModule}' must be explicitly marked unsafe while prerequisite gaps remain.");
        }
    }

    public function test_safe_first_pilot_ui_scope_includes_only_internal_firm_admin_modules_as_safe(): void
    {
        $safeModules = array_filter($this->service->safeFirstPilotUiScope(), fn (array $m) => $m['safe'] === true);
        $safeModuleNames = array_column($safeModules, 'module');

        foreach ([
            'platform_admin_shell_basic_dashboard', 'firm_onboarding_internal_settings',
            'users_roles_current_permission_boundaries', 'clients', 'matters', 'conflicts',
            'tasks_deadlines', 'documents_internal_only', 'template_pack_install_preview_pilot_pack_only',
            'manual_payment_recording_only', 'billing_invoices_internal_only',
        ] as $expectedSafeModule) {
            $this->assertContains($expectedSafeModule, $safeModuleNames, "Module '{$expectedSafeModule}' should be part of the safe first-pilot UI scope.");
        }

        foreach (['client_portal_mobile', 'trust_iolta_ui', 'ai_ui'] as $forbiddenSafeModule) {
            $this->assertNotContains($forbiddenSafeModule, $safeModuleNames, "Module '{$forbiddenSafeModule}' must not be marked safe.");
        }
    }

    public function test_online_stripe_checkout_and_org_admin_and_dedicated_fleet_ui_are_excluded_from_safe_scope(): void
    {
        $safeModuleNames = array_column(
            array_filter($this->service->safeFirstPilotUiScope(), fn (array $m) => $m['safe'] === true),
            'module',
        );

        $this->assertNotContains('online_payment_stripe_checkout', $safeModuleNames);
        $this->assertNotContains('org_admin_ui', $safeModuleNames);
        $this->assertNotContains('dedicated_private_enterprise_fleet_ui', $safeModuleNames);

        $mustWaitModules = array_column($this->service->uiModulesThatMustWait(), 'module');

        $this->assertContains('online_payment_stripe_checkout', $mustWaitModules);
        $this->assertContains('org_admin_ui', $mustWaitModules);
        $this->assertContains('dedicated_private_enterprise_fleet_ui', $mustWaitModules);
        $this->assertContains('client_portal_mobile', $mustWaitModules);
    }

    public function test_ui_modules_that_must_wait_lists_each_blocked_module_with_prerequisite_gap_references(): void
    {
        $mustWait = $this->service->uiModulesThatMustWait();
        $allGapKeys = $this->service->gapKeys();

        $this->assertNotEmpty($mustWait);

        foreach ($mustWait as $entry) {
            $this->assertArrayHasKey('module', $entry);
            $this->assertArrayHasKey('blocked_by', $entry);
            $this->assertArrayHasKey('notes', $entry);
            $this->assertNotEmpty($entry['blocked_by'], "Module '{$entry['module']}' must reference at least one prerequisite gap.");

            foreach ($entry['blocked_by'] as $gapKey) {
                $this->assertContains($gapKey, $allGapKeys, "uiModulesThatMustWait() references unknown gap key '{$gapKey}' for module '{$entry['module']}'.");
            }
        }
    }

    public function test_universal_ui_contract_includes_the_required_rules(): void
    {
        $contract = $this->service->universalUiContract();
        $joined = implode(' | ', $contract);

        $this->assertStringContainsStringIgnoringCase('tenant context', $joined);
        $this->assertStringContainsStringIgnoringCase('route/page authorization', $joined);
        $this->assertStringContainsStringIgnoringCase('EntitlementService', $joined);
        $this->assertStringContainsStringIgnoringCase('audit', $joined);
        $this->assertStringContainsStringIgnoringCase('never hidden navigation alone', $joined);
        $this->assertStringContainsStringIgnoringCase('feature flags only to restrict', $joined);
        $this->assertStringContainsStringIgnoringCase('signed-URL service', $joined);
        $this->assertStringContainsStringIgnoringCase('second audit, permission, entitlement, license, or signature system', $joined);
    }
}
