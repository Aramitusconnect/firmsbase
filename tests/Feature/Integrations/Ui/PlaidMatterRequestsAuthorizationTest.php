<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\PlaidMatterRequestsPage;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlaidMatterRequestsAuthorizationTest — regression coverage for two
 * defects found in PlaidMatterRequestsPage:
 *
 *   1. canAccess()/table() previously had NO role-tier check at all —
 *      only PlaidEntitlementPolicyService::isEnabled($firm), which only
 *      confirms the FIRM purchased Plaid, not that THIS user's role may
 *      view financial-evidence matter requests. Fixed by adding
 *      FinancialIntegrationAccessPolicyService::canView(), matching the
 *      established pattern on PlaidOverviewPage.
 *
 *   2. The "new request" action created a
 *      FinancialEvidenceMatterRequest from a raw, client-submitted
 *      `matter_id` with no server-side re-check that the matter belongs
 *      to the actor's own firm — the Select's options are firm-scoped
 *      for DISPLAY only, and Filament does not enforce that server-side.
 *      Fixed by re-validating the submitted matter_id against a
 *      firm-scoped Matter query before creating the request.
 *
 * Mirrors PlaidFirmPanelNavigationAuthorizationTest's shape for the
 * role-ceiling assertions, and FirmIntegrationDirectRouteAccessTest's
 * shape for the direct-route-hit assertion.
 */
final class PlaidMatterRequestsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    private const DENIED_ROLES = [
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
    ];

    // ------------------------------------------------------------
    // canAccess() / shouldRegisterNavigation() role ceiling
    // ------------------------------------------------------------

    public function test_every_view_ceiling_role_can_access_the_page_when_entitled(): void
    {
        foreach (self::VIEW_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(PlaidMatterRequestsPage::canAccess(), "Role {$role->value} must access PlaidMatterRequestsPage.");
            $this->assertTrue(PlaidMatterRequestsPage::shouldRegisterNavigation());
        }
    }

    public function test_roles_outside_the_view_ceiling_cannot_access_the_page_even_when_entitled(): void
    {
        foreach (self::DENIED_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertFalse(PlaidMatterRequestsPage::canAccess(), "Role {$role->value} must NOT access PlaidMatterRequestsPage.");
            $this->assertFalse(PlaidMatterRequestsPage::shouldRegisterNavigation());
        }
    }

    // ------------------------------------------------------------
    // Direct route hit — a below-ceiling role is forbidden even by
    // hitting the URL directly, not merely hidden from navigation.
    // ------------------------------------------------------------

    public function test_a_below_ceiling_role_is_forbidden_on_the_direct_route(): void
    {
        $firm = $this->plaidEntitledFirm();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->actingAs($firmUser->user)
            ->get(PlaidMatterRequestsPage::getUrl())
            ->assertForbidden();
    }

    public function test_an_entitled_view_ceiling_role_can_reach_the_direct_route(): void
    {
        $firm = $this->plaidEntitledFirm();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->actingAs($firmUser->user)
            ->get(PlaidMatterRequestsPage::getUrl())
            ->assertOk();
    }

    public function test_a_disentitled_firm_denies_access_even_for_an_otherwise_eligible_role(): void
    {
        $firm = Firm::factory()->create(); // no Plaid entitlement enabled
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(PlaidMatterRequestsPage::canAccess());
    }

    // ------------------------------------------------------------
    // create() — server-side re-validation that matter_id belongs to
    // the actor's own firm.
    // ------------------------------------------------------------

    public function test_submitting_a_cross_firm_matter_id_is_rejected_and_creates_no_request(): void
    {
        $actorFirm = $this->plaidEntitledFirm();
        $actor = $this->actingAsRole($actorFirm, FirmUserRole::FirmOwner);

        $otherFirm = Firm::factory()->create();
        $foreignMatter = $this->runWithFirmContext($otherFirm, fn () => Matter::factory()->forFirm($otherFirm)->create());

        // Layer 1: even the Livewire/Filament form round trip rejects a
        // forged cross-firm matter_id — the Select's dynamic options()
        // closure is re-evaluated server-side against the ACTOR's own
        // firm on every submission, and Filament validates the submitted
        // value against that resolved list, producing a validation error
        // rather than silently accepting it.
        $test = $this->runWithFirmContext(
            $actorFirm,
            fn () => Livewire::test(PlaidMatterRequestsPage::class),
        );

        $this->runWithFirmContext($actorFirm, function () use ($test, $foreignMatter) {
            $test->mountAction('newRequest');
            $test->setActionData([
                'matter_id' => $foreignMatter->id,
                'requested_products' => ['bank_account'],
                'purpose' => 'cross-firm forged submission',
            ]);
            $test->callMountedAction();
        });

        $test->assertHasActionErrors(['matter_id']);

        $created = $this->runWithFirmContext(
            $actorFirm,
            fn () => FinancialEvidenceMatterRequest::query()->where('matter_id', $foreignMatter->id)->count(),
        );

        $this->assertSame(0, $created, 'A cross-firm matter_id must never result in a created financial-evidence matter request.');

        // Layer 2: the defense-in-depth server-side re-validation added to
        // the action's own closure (Matter::query()->where('firm_id', ...)
        // ->where('id', ...)->firstOrFail()) is exercised DIRECTLY here,
        // bypassing Filament's Select-options validation entirely. This
        // proves the create() action itself does not trust a raw
        // matter_id even if some future change (a non-searchable Select,
        // a static option list, a different input type, or a Filament
        // upgrade) ever stopped scoping the options()/validating them —
        // the app-level check in the action closure must independently
        // refuse to create a request for a matter outside the actor's
        // own firm.
        $page = new PlaidMatterRequestsPage;
        $method = new \ReflectionMethod(PlaidMatterRequestsPage::class, 'newRequestAction');
        $method->setAccessible(true);
        /** @var Action $action */
        $action = $method->invoke($page);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->runWithFirmContext($actorFirm, function () use ($action, $foreignMatter) {
                $action->call([
                    'data' => [
                        'matter_id' => $foreignMatter->id,
                        'requested_products' => ['bank_account'],
                        'purpose' => 'cross-firm forged submission, bypassing Select validation',
                    ],
                ]);
            });
        } finally {
            $created = $this->runWithFirmContext(
                $actorFirm,
                fn () => FinancialEvidenceMatterRequest::query()->where('matter_id', $foreignMatter->id)->count(),
            );

            $this->assertSame(0, $created, 'The action closure itself must never create a request for a cross-firm matter_id, even bypassing Select-level validation.');
        }
    }

    public function test_submitting_a_valid_own_firm_matter_id_creates_the_request(): void
    {
        $firm = $this->plaidEntitledFirm();
        $actor = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(PlaidMatterRequestsPage::class),
        );

        $this->runWithFirmContext($firm, function () use ($test, $matter) {
            $test->mountAction('newRequest');
            $test->setActionData([
                'matter_id' => $matter->id,
                'requested_products' => ['bank_account'],
                'purpose' => 'legitimate same-firm request',
            ]);
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();

        $created = $this->runWithFirmContext(
            $firm,
            fn () => FinancialEvidenceMatterRequest::query()->where('matter_id', $matter->id)->where('firm_id', $firm->id)->count(),
        );

        $this->assertSame(1, $created, 'A genuine same-firm submission must create exactly one financial-evidence matter request.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function plaidEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
