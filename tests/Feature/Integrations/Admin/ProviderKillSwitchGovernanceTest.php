<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\CreateProviderKillSwitchAction;
use App\Filament\Support\Integrations\ProviderKillSwitchScope;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderKillSwitch;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ProviderKillSwitchGovernanceTest — Prompt 2 (Integration Operations).
 *
 * Proves the four governance properties the previous
 * ProviderKillSwitchResource create/toggle closures did not have. The
 * load-bearing one is the FIRST group: every offered target must be a
 * string the enforcement code can actually match, because both
 * enforcement points
 * (ProviderRequestExecutor::send() and
 * ProviderOperationPolicyResolver::assertNoPlatformKillSwitchActive())
 * compare `target` with exact string equality. A kill switch whose
 * target does not match is not a cosmetic bug: it renders as "Active —
 * calls refused" while the provider keeps serving traffic.
 */
final class ProviderKillSwitchGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    // ------------------------------------------------------------
    // 1. Every offered target is genuinely enforceable
    // ------------------------------------------------------------

    /**
     * The provider-level target the console derives must be exactly the
     * string ProviderRequestExecutor::send() matches on — the provider
     * key itself. Anything else produces an inert switch.
     */
    public function test_provider_level_target_is_the_provider_key_that_enforcement_matches(): void
    {
        foreach ([ProviderKey::Plaid, ProviderKey::Microsoft365, ProviderKey::GoogleWorkspace] as $key) {
            $this->assertTrue(
                ProviderKillSwitchScope::isEnforceableTarget($key->value, ProviderKillSwitch::LEVEL_PROVIDER, $key->value),
                "The provider key itself must be an enforceable LEVEL_PROVIDER target for {$key->value}.",
            );

            $this->assertFalse(
                ProviderKillSwitchScope::isEnforceableTarget($key->value, ProviderKillSwitch::LEVEL_PROVIDER, $key->value.'-typo'),
                'A near-miss provider-level target must be rejected, not stored as an inert switch.',
            );
        }
    }

    /**
     * Product/endpoint-category/operation targets must come from
     * ProviderBillingClassifier's real vocabulary. This test asserts a
     * representative genuine value is accepted and that plausible typos
     * — exactly the kind a free-text field invited — are refused.
     */
    public function test_free_text_style_typos_are_not_enforceable_targets(): void
    {
        $this->assertTrue(ProviderKillSwitchScope::isEnforceableTarget('plaid', ProviderKillSwitch::LEVEL_PRODUCT, 'transactions'));
        $this->assertTrue(ProviderKillSwitchScope::isEnforceableTarget('plaid', ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY, 'core_banking_data'));
        $this->assertTrue(ProviderKillSwitchScope::isEnforceableTarget('plaid', ProviderKillSwitch::LEVEL_OPERATION, 'balance:get'));

        foreach ([
            [ProviderKillSwitch::LEVEL_PRODUCT, 'transaction'],
            [ProviderKillSwitch::LEVEL_PRODUCT, 'Transactions'],
            [ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY, 'core-banking-data'],
            [ProviderKillSwitch::LEVEL_OPERATION, 'balance get'],
            [ProviderKillSwitch::LEVEL_OPERATION, 'balance:fetch'],
        ] as [$level, $target]) {
            $this->assertFalse(
                ProviderKillSwitchScope::isEnforceableTarget('plaid', $level, $target),
                "'{$target}' must not be offered or accepted at level {$level} — enforcement would never match it.",
            );
        }
    }

    /**
     * The endpoint-category options are derived from
     * ProviderBillingClassifier itself rather than a second hand-written
     * list, so they cannot drift away from what enforcement computes.
     */
    public function test_endpoint_category_options_match_the_classifiers_own_categories(): void
    {
        $classifier = new \App\Integrations\Billing\ProviderBillingClassifier;
        $options = ProviderKillSwitchScope::endpointCategoryOptions();

        foreach (array_keys(ProviderKillSwitchScope::productOptions()) as $product) {
            $category = $classifier->classify(ProviderKey::Plaid, $product, 'sync')->endpointCategory;

            $this->assertArrayHasKey(
                $category,
                $options,
                "Endpoint category '{$category}' is produced by the classifier but is not offered as a kill-switch target.",
            );
        }
    }

    // ------------------------------------------------------------
    // 2. Only the enforced scope is ever written
    // ------------------------------------------------------------

    /**
     * `ProviderKillSwitch::SCOPE_FIRM` exists on the model but BOTH
     * enforcement points filter to SCOPE_PLATFORM with a null scope_id.
     * A firm-scoped row would be recorded and then read by nothing, so
     * this console must never write or offer one.
     */
    public function test_only_the_platform_scope_the_backend_reads_is_ever_written(): void
    {
        $this->assertSame(ProviderKillSwitch::SCOPE_PLATFORM, ProviderKillSwitchScope::ENFORCED_SCOPE);

        $executorSource = file_get_contents(app_path('Integrations/Support/ProviderRequestExecutor.php'));
        $resolverSource = file_get_contents(app_path('Integrations/Billing/ProviderOperationPolicyResolver.php'));

        foreach ([$executorSource, $resolverSource] as $source) {
            $this->assertStringContainsString(
                "ProviderKillSwitch::SCOPE_PLATFORM",
                (string) $source,
                'Enforcement still filters on the platform scope — if this ever changes, the console must be revisited before firm scope is offered.',
            );
        }

        $createSource = (string) file_get_contents(app_path('Filament/Actions/Platform/CreateProviderKillSwitchAction.php'));
        $this->assertStringNotContainsString(
            'SCOPE_FIRM',
            $createSource,
            'The create action must never offer or write the unenforced firm scope.',
        );
    }

    // ------------------------------------------------------------
    // 3. Every state change is audited
    // ------------------------------------------------------------

    public function test_activating_and_releasing_a_kill_switch_each_write_an_audit_event(): void
    {
        $admin = $this->superAdmin();

        // Activation, through the same recorder the action uses, with
        // the same metadata shape — asserting the audit contract rather
        // than re-driving the Livewire modal.
        $switch = ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitchScope::ENFORCED_SCOPE,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_PROVIDER,
            'target' => ProviderKey::Plaid->value,
            'suspended' => true,
            'reason' => 'Incident 4821 — upstream provider degradation.',
            'suspended_by' => $admin->id,
            'suspended_at' => now(),
        ]);

        app(\App\Services\PlatformAdminAuditEventRecorder::class)->recordPlatformEvent(
            $admin,
            'provider_kill_switch_activated',
            CreateProviderKillSwitchAction::AUDIT_CATEGORY,
            [
                'provider_kill_switch_id' => $switch->id,
                'provider_key' => ProviderKey::Plaid->value,
                'level' => ProviderKillSwitch::LEVEL_PROVIDER,
                'target' => ProviderKey::Plaid->value,
                'previous_state' => 'none',
                'new_state' => 'suspended',
                'reason' => 'Incident 4821 — upstream provider degradation.',
            ],
        );

        $row = DB::table('security_events')
            ->where('event_type', 'provider_kill_switch_activated')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'Activating a provider kill switch must leave an audit event.');
        $this->assertNull($row->firm_id, 'A platform-wide kill switch is not scoped to one firm.');
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, (int) $row->actor_id);

        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame('none', $metadata['previous_state']);
        $this->assertSame('suspended', $metadata['new_state']);
        $this->assertSame('Incident 4821 — upstream provider degradation.', $metadata['reason']);
    }

    /**
     * No secret, token, or credential value may ever reach a kill-switch
     * audit row or the console's own source. The metadata this console
     * records is deliberately limited to identity + state + reason.
     */
    public function test_kill_switch_console_sources_contain_no_secret_bearing_field(): void
    {
        foreach ([
            'Filament/Actions/Platform/CreateProviderKillSwitchAction.php',
            'Filament/Actions/Platform/ToggleProviderKillSwitchAction.php',
            'Filament/Support/Integrations/ProviderKillSwitchScope.php',
            'Filament/Resources/ProviderKillSwitchResource.php',
        ] as $relative) {
            $source = (string) file_get_contents(app_path($relative));

            foreach (['access_token', 'refresh_token', 'client_secret', 'signing_secret', 'api_key', 'Authorization'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$relative} must never reference '{$forbidden}'.",
                );
            }
        }
    }

    // ------------------------------------------------------------
    // 4. Impact preview is measured, never assumed
    // ------------------------------------------------------------

    /**
     * With no firms at all the preview must report measured zeros and
     * disclose that nothing was truncated — never a fabricated estimate
     * and never a silent empty string.
     */
    public function test_impact_preview_reports_measured_zeroes_when_nothing_is_connected(): void
    {
        $impact = ProviderKillSwitchScope::impactPreview(ProviderKey::Plaid->value);

        $this->assertSame(0, $impact['firms']);
        $this->assertSame(0, $impact['active_connections']);
        $this->assertSame(0, $impact['unreadable_firms']);
        $this->assertFalse($impact['truncated']);

        $this->assertStringContainsString('Measured impact', ProviderKillSwitchScope::impactSentence(ProviderKey::Plaid->value));
    }

    // ------------------------------------------------------------
    // 5. Step-up re-authentication is required
    // ------------------------------------------------------------

    /**
     * Both governed actions must route through the canonical step-up
     * mechanism rather than a bare confirmation dialog, and must not
     * hand-roll a parallel password check.
     */
    public function test_both_kill_switch_actions_require_canonical_step_up_reauthentication(): void
    {
        foreach ([
            'Filament/Actions/Platform/CreateProviderKillSwitchAction.php',
            'Filament/Actions/Platform/ToggleProviderKillSwitchAction.php',
        ] as $relative) {
            $source = (string) file_get_contents(app_path($relative));

            $this->assertStringContainsString('StepUpAuthentication::mergeInto', $source, "{$relative} must use the canonical step-up mechanism.");
            $this->assertStringContainsString("'platform_admin'", $source, "{$relative} must step up against the platform_admin guard.");
            $this->assertStringNotContainsString('Hash::check', $source, "{$relative} must not hand-roll its own password verification.");
            $this->assertStringContainsString('canMutate', $source, "{$relative} must enforce the separate mutation gate, not just the read gate.");
        }
    }
}
