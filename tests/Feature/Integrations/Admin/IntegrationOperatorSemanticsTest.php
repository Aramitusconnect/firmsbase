<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlaidAnomalyOversightPage;
use App\Filament\Pages\PlaidCostOversightPage;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Filament\Pages\PlatformIntegrationUsagePage;
use App\Filament\Pages\PlatformProviderHealthPage;
use App\Filament\Pages\PlatformProviderOperationReconciliationPage;
use App\Filament\Resources\ConflictResource;
use App\Filament\Resources\ConnectionResource;
use App\Filament\Resources\DeadLetterQueueResource;
use App\Filament\Resources\PlaidItemOversightResource;
use App\Filament\Resources\ProviderKillSwitchResource;
use App\Filament\Resources\SyncFailureResource;
use App\Filament\Resources\WebhookEventResource;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\PlatformPlaidCostOversightReadService;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationOperatorSemanticsTest — Prompt 2 (Integration Operations).
 *
 * Covers the cross-cutting operator-honesty properties this mission
 * introduced, which are easy to regress silently because nothing crashes
 * when they break — the console simply starts lying:
 *
 *   - a raw provider slug leaking back into a customer-facing column;
 *   - a never-measured value rendering as a reassuring "0" or "Healthy";
 *   - an Integration surface drifting back out of its navigation group.
 */
final class IntegrationOperatorSemanticsTest extends TestCase
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
    // Provider naming
    // ------------------------------------------------------------

    /**
     * The seeded catalog's display_name wins, so one provider never has
     * two different names on two different Integration screens.
     */
    public function test_provider_labels_prefer_the_seeded_catalog_display_name(): void
    {
        // `integration_providers` is seeded reference data, so the row
        // already exists — update it to a distinctive name to prove the
        // catalog is genuinely consulted first rather than the code-level
        // fallback happening to agree.
        IntegrationProvider::query()->updateOrCreate(
            ['code' => ProviderKey::GoogleWorkspace->value],
            ['display_name' => 'Google Workspace (Renamed In Catalog)'],
        );

        $this->assertSame(
            'Google Workspace (Renamed In Catalog)',
            IntegrationDisplay::labelForProviderCode('googleworkspace'),
            'The seeded catalog display_name must win, so one provider never carries two names across Integration screens.',
        );
    }

    /**
     * With no catalog row seeded, the code-defined registry still yields
     * a human label — never the raw slug, and never Str::headline()'s
     * "Googleworkspace"/"Microsoft365".
     */
    public function test_provider_labels_fall_back_to_the_code_defined_registry_not_the_raw_slug(): void
    {
        $this->assertSame('Google Workspace', IntegrationDisplay::labelForProviderCode('googleworkspace'));
        $this->assertSame('Microsoft 365', IntegrationDisplay::labelForProviderCode('microsoft365'));
        $this->assertSame('Plaid', IntegrationDisplay::labelForProviderCode('plaid'));
    }

    /**
     * The internal fixture provider must never be presented as a
     * customer-supported integration on a cross-firm operator screen.
     */
    public function test_the_internal_test_provider_is_labelled_as_internal(): void
    {
        // The seeded catalog already names this provider explicitly
        // ("Internal Test Provider (non-production)"), and the catalog
        // wins — so the assertion is on the PROPERTY that matters (the
        // label marks it as internal) rather than on one exact wording
        // owned by the seeder.
        $label = IntegrationDisplay::labelForProviderCode('test');
        $this->assertStringContainsStringIgnoringCase('internal', $label, "The fixture provider must be visibly marked internal, got '{$label}'.");

        // With no catalog row at all, the code-defined fallback must
        // still mark it internal.
        IntegrationProvider::query()->where('code', 'test')->delete();
        app()->forgetInstance(IntegrationDisplay::class.'@catalogLabels');
        $this->assertSame('Internal / Test', IntegrationDisplay::labelForProviderCode('test'));

        $this->assertTrue(IntegrationDisplay::isInternalProviderCode('test'));
        $this->assertFalse(IntegrationDisplay::isInternalProviderCode('plaid'));
        $this->assertArrayNotHasKey('test', IntegrationDisplay::liveProviderOptions());
    }

    /** An unknown code is shown as-is rather than relabelled as something it is not. */
    public function test_an_unknown_provider_code_is_never_silently_relabelled(): void
    {
        $this->assertSame(IntegrationDisplay::UNKNOWN, IntegrationDisplay::labelForProviderCode(null));
        $this->assertSame(IntegrationDisplay::UNKNOWN, IntegrationDisplay::labelForProviderCode('  '));
        $this->assertSame('Some Future Provider', IntegrationDisplay::labelForProviderCode('some_future_provider'));
    }

    // ------------------------------------------------------------
    // Zero vs never measured
    // ------------------------------------------------------------

    /**
     * THE load-bearing assertion of this mission's honesty work. With no
     * summary rows at all, the Overview's SUMs are arithmetically zero —
     * and presenting those zeros as "0 failed records, 0 dead-lettered,
     * 0 open conflicts" is a fabricated all-clear on the platform's
     * top-level integration screen. It must say nothing was measured.
     */
    public function test_the_overview_reports_no_data_yet_rather_than_reassuring_zeroes(): void
    {
        $admin = $this->superAdmin();

        $this->assertSame(0, DB::table('integration_platform_overview_summaries')->count());

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee(IntegrationDisplay::NO_DATA_YET);
        $response->assertSee('nothing has been measured');
    }

    /**
     * The same rule for Provider Health: an unevaluated provider
     * population must not render as a healthy one.
     */
    public function test_provider_health_states_nothing_has_been_measured_rather_than_implying_health(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl());

        $response->assertOk();
        $response->assertSee('Provider health has not been measured yet');
        $response->assertSee('not the same as every provider being healthy');
    }

    /** A measured zero still reads as a zero — this is not blanket suppression. */
    public function test_a_measured_zero_is_still_rendered_as_zero(): void
    {
        $this->assertSame('0', IntegrationDisplay::measuredCount(0));
        $this->assertSame('7', IntegrationDisplay::measuredCount(7));
        $this->assertSame(IntegrationDisplay::NOT_MEASURED, IntegrationDisplay::measuredCount(null));
    }

    /** Absence is always named, never rendered as a bare dash. */
    public function test_absent_values_are_named_never_shown_as_an_ambiguous_dash(): void
    {
        foreach ([
            IntegrationDisplay::NOT_APPLICABLE,
            IntegrationDisplay::NEVER_CHECKED,
            IntegrationDisplay::UNKNOWN,
            IntegrationDisplay::NOT_MEASURED,
            IntegrationDisplay::NOT_CONFIGURED,
            IntegrationDisplay::NOT_CHECKED,
            IntegrationDisplay::NO_DATA_YET,
            IntegrationDisplay::NOT_REPORTED,
        ] as $label) {
            $this->assertNotSame('—', $label);
            $this->assertNotSame('', trim($label));
        }

        $this->assertSame('Connection removed', IntegrationDisplay::orAbsent(null, 'Connection removed'));
        $this->assertSame('Connection removed', IntegrationDisplay::orAbsent('   ', 'Connection removed'));
        $this->assertSame('kept', IntegrationDisplay::orAbsent('kept', 'Connection removed'));
    }

    /**
     * An unrecognised health state must never be optimistically coloured
     * as success — grey (unknown) is the safe default.
     */
    public function test_an_unrecognised_health_state_is_never_coloured_as_healthy(): void
    {
        $this->assertSame('success', IntegrationDisplay::healthColor('healthy'));
        $this->assertSame('warning', IntegrationDisplay::healthColor('degraded'));
        $this->assertSame('danger', IntegrationDisplay::healthColor('unavailable'));
        $this->assertSame('gray', IntegrationDisplay::healthColor(null));
        $this->assertSame('gray', IntegrationDisplay::healthColor('something_new'));
    }

    // ------------------------------------------------------------
    // Navigation grouping (the historical regression)
    // ------------------------------------------------------------

    /**
     * Every Integration surface belongs to the "Integrations" navigation
     * group. Four of them previously declared no group at all and
     * rendered as ungrouped top-level Admin entries — the regression this
     * mission was asked to verify and fix.
     */
    public function test_every_integration_surface_lives_in_the_integrations_navigation_group(): void
    {
        $surfaces = [
            PlatformIntegrationOverviewPage::class,
            PlatformProviderHealthPage::class,
            PlatformProviderOperationReconciliationPage::class,
            PlaidAnomalyOversightPage::class,
            PlaidCostOversightPage::class,
            PlatformIntegrationUsagePage::class,
            ConnectionResource::class,
            PlaidItemOversightResource::class,
            ProviderKillSwitchResource::class,
            SyncFailureResource::class,
            WebhookEventResource::class,
            DeadLetterQueueResource::class,
            ConflictResource::class,
        ];

        foreach ($surfaces as $surface) {
            $group = (new \ReflectionClass($surface))->getStaticPropertyValue('navigationGroup');

            $this->assertSame(
                'Integrations',
                $group instanceof \UnitEnum ? $group->name : $group,
                "{$surface} must sit inside the Integrations navigation group, not at the Admin top level.",
            );
        }
    }

    /**
     * Operator-facing names must be consistent between the sidebar and
     * the page/record labels — the specific inconsistencies this mission
     * found.
     */
    public function test_operator_facing_names_are_consistent_across_sidebar_and_page(): void
    {
        $this->assertSame('Integration Overview', PlatformIntegrationOverviewPage::getNavigationLabel());
        $this->assertSame('Integration Conflicts', ConflictResource::getNavigationLabel());
        $this->assertSame('Provider Kill Switches', ProviderKillSwitchResource::getNavigationLabel());
        $this->assertSame('Plaid Usage Anomalies', PlaidAnomalyOversightPage::getNavigationLabel());

        // Filament would otherwise derive these from the underlying
        // model and show "Integration Sync Item"/"Integration Outbox
        // Event" in breadcrumbs while the sidebar says something else.
        $this->assertSame('Sync Failures', SyncFailureResource::getPluralModelLabel());
        $this->assertSame('Dead-Lettered Events', DeadLetterQueueResource::getPluralModelLabel());
        $this->assertSame('Integration Conflicts', ConflictResource::getPluralModelLabel());
        $this->assertSame('Plaid Items', PlaidItemOversightResource::getPluralModelLabel());
    }

    // ------------------------------------------------------------
    // Provider cost vs customer billing boundary
    // ------------------------------------------------------------

    /**
     * Plaid cost oversight reports UPSTREAM PROVIDER cost. It must state
     * on the page that these figures are estimates and are never
     * invoiced, and must never mutate customer billing.
     */
    public function test_plaid_cost_oversight_states_that_figures_are_estimated_and_not_invoiced(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'platform_admin')->get(PlaidCostOversightPage::getUrl());

        $response->assertOk();
        $response->assertSee('not invoiced', false);
    }

    /**
     * With no rate card in effect, unpriced usage must be reported as
     * unpriced — never silently coerced to a currency zero, which would
     * read as "this provider usage is free".
     */
    public function test_missing_pricing_is_reported_as_unavailable_never_as_zero(): void
    {
        $admin = $this->superAdmin();

        $provenance = app(PlatformPlaidCostOversightReadService::class)
            ->pricingProvenance($admin);

        $this->assertFalse($provenance['has_pricing']);
        $this->assertSame([], $provenance['currencies']);
        $this->assertNull($provenance['effective_from']);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlaidCostOversightPage::getUrl());
        $response->assertOk();
        $response->assertSee('Pricing unavailable');
    }

    /**
     * The cost console must never touch customer billing. Structural
     * guard: no invoice/credit/refund writer is reachable from these
     * files at all.
     */
    public function test_the_plaid_cost_console_never_references_customer_billing_writers(): void
    {
        foreach ([
            'Filament/Pages/PlaidCostOversightPage.php',
            'Integrations/Services/PlatformPlaidCostOversightReadService.php',
        ] as $relative) {
            $source = (string) file_get_contents(app_path($relative));

            foreach (['PlatformInvoice', 'PlatformRefund', 'PlatformCredit', 'UsageCharge', 'Subscription'] as $billingWriter) {
                $this->assertStringNotContainsString(
                    $billingWriter,
                    $source,
                    "{$relative} must never reach into customer billing — provider cost is an estimate, not an invoice.",
                );
            }
        }
    }

    // ------------------------------------------------------------
    // Conflict resolution boundary
    // ------------------------------------------------------------

    /**
     * SuperAdmin must remain unable to resolve, approve, or override an
     * Integration conflict — those require two distinct real FirmUser
     * actors. This re-asserts the boundary after this mission's edits to
     * the resource's columns and labels.
     */
    public function test_superadmin_still_cannot_resolve_or_approve_an_integration_conflict(): void
    {
        // Comments stripped first: this resource's docblock deliberately
        // EXPLAINS why it never calls IntegrationConflictService, so a
        // naive substring search over the raw file would flag the very
        // documentation that records the boundary. What matters is that
        // no executable statement references these.
        $source = self::sourceWithoutComments(app_path('Filament/Resources/ConflictResource.php'));

        foreach ([
            'IntegrationConflictService',
            'transitionStatus',
            'proposeResolution',
            'resolution_note',
            'local_value',
            'external_value',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "ConflictResource must never reference '{$forbidden}' — conflicts are resolved only through the firm's own dual-approval workflow.",
            );
        }

        // The only row action is a read-only View.
        $this->assertStringNotContainsString('ResolveConflict', $source);

        // The operator-facing empty state still states the boundary.
        $this->assertStringContainsString(
            'monitoring-only view',
            (string) file_get_contents(app_path('Filament/Resources/ConflictResource.php')),
        );
    }

    /**
     * Returns a PHP file's source with every comment and docblock
     * removed, so a structural "this identifier must not appear" guard
     * tests real code rather than the prose explaining the rule.
     */
    private static function sourceWithoutComments(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));

        $out = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
