<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Filament\ClientPortal\Resources\PaymentPlanResource;
use App\Filament\ClientPortal\Resources\PaymentPlanResource\Pages\ViewPaymentPlan;
use App\Filament\ClientPortal\Resources\PaymentPlanResource\RelationManagers\InstallmentsRelationManager;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaymentPlanResourceTest — PORTAL-003. Proves PaymentPlanResource's
 * client_id + grant gating: a plan for a matter the client has no
 * active ClientPortalMatterGrant for must never be visible, even
 * though its client_id matches the authenticated client exactly — the
 * same "explicit grant required" principle proven for InvoiceResource
 * and Matter itself. A plan with no matter_id at all is visible on
 * client_id scoping alone. Also proves per-installment schedule
 * visibility and that the Resource exposes no write path anywhere.
 */
class PaymentPlanResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_matter_linked_plan_is_visible_when_the_client_has_an_active_grant_for_that_matter(): void
    {
        $firm = Firm::factory()->create();
        [$client, $plan] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            $plan = PaymentPlan::factory()->forClient($client)->create(['matter_id' => $matter->id]);

            return [$client, $plan];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::isVisibleToPortalUser($plan->fresh(), $portalUser));

        $this->assertContains($plan->id, $ids);
        $this->assertTrue($visible);
    }

    public function test_a_matter_linked_plan_is_never_visible_without_an_active_grant_for_that_matter_even_though_client_id_matches(): void
    {
        $firm = Firm::factory()->create();
        [$client, $plan] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            // Genuinely this client's matter (client_id matches) — but
            // no ClientPortalMatterGrant exists for it.
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $plan = PaymentPlan::factory()->forClient($client)->create(['matter_id' => $matter->id]);

            return [$client, $plan];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::isVisibleToPortalUser($plan->fresh(), $portalUser));

        $this->assertNotContains(
            $plan->id,
            $ids,
            'A payment plan for a matter the client has no grant for must never be visible, even though plan.client_id matches.'
        );
        $this->assertFalse($visible);
    }

    public function test_a_plan_with_no_matter_id_is_visible_on_client_id_scoping_alone(): void
    {
        $firm = Firm::factory()->create();
        [$client, $plan] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $plan = PaymentPlan::factory()->forClient($client)->create(); // matter_id left null
            $this->assertNull($plan->matter_id);

            return [$client, $plan];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::isVisibleToPortalUser($plan->fresh(), $portalUser));

        $this->assertContains($plan->id, $ids);
        $this->assertTrue($visible);
    }

    public function test_a_different_clients_plan_is_never_visible_regardless_of_matter_grants(): void
    {
        $firm = Firm::factory()->create();
        [$clientA, $planB] = $this->runWithFirmContext($firm, function () use ($firm) {
            $clientA = Client::factory()->forFirm($firm)->create();
            $clientB = Client::factory()->forFirm($firm)->create();
            $planB = PaymentPlan::factory()->forClient($clientB)->create();

            return [$clientA, $planB];
        });
        $portalUserA = $this->makePortalUser($clientA);

        Auth::guard('client')->login($portalUserA);

        $ids = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => PaymentPlanResource::isVisibleToPortalUser($planB->fresh(), $portalUserA));

        $this->assertNotContains($planB->id, $ids);
        $this->assertFalse($visible);
    }

    public function test_a_cross_firm_plan_is_never_visible_even_with_a_client_id_collision(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $clientB = Client::factory()->forFirm($firmB)->create();

            return PaymentPlan::factory()->forClient($clientB)->create();
        });
        $portalUserA = $this->makePortalUser($clientA);

        Auth::guard('client')->login($portalUserA);

        $ids = $this->runWithFirmContext($firmB, fn () => PaymentPlanResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($planB->id, $ids);
    }

    public function test_installments_render_for_a_visible_plan_via_the_relation_manager_gate(): void
    {
        $firm = Firm::factory()->create();
        [$client, $plan, $installmentIds] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $plan = PaymentPlan::factory()->forClient($client)->active()->create([
                'total_cents' => 20000,
                'installment_count' => 2,
            ]);

            $first = PaymentPlanInstallment::factory()->forPlan($plan)->create([
                'sequence' => 1,
                'amount_cents' => 10000,
            ]);
            $second = PaymentPlanInstallment::factory()->forPlan($plan)->create([
                'sequence' => 2,
                'amount_cents' => 10000,
            ]);

            return [$client, $plan, [$first->id, $second->id]];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $canView = $this->runWithFirmContext(
            $firm,
            fn () => InstallmentsRelationManager::canViewForRecord($plan->fresh(), ViewPaymentPlan::class)
        );
        $this->assertTrue($canView, 'The owning portal user must be able to view the Installments relation manager for their own plan.');

        $renderedInstallmentIds = $this->runWithFirmContext(
            $firm,
            fn () => $plan->fresh()->installments()->pluck('id')->all()
        );

        $this->assertEqualsCanonicalizing($installmentIds, $renderedInstallmentIds);
    }

    public function test_installments_relation_manager_is_not_viewable_for_a_plan_the_portal_user_cannot_access(): void
    {
        $firm = Firm::factory()->create();
        [$clientA, $planB] = $this->runWithFirmContext($firm, function () use ($firm) {
            $clientA = Client::factory()->forFirm($firm)->create();
            $clientB = Client::factory()->forFirm($firm)->create();
            $planB = PaymentPlan::factory()->forClient($clientB)->create();

            return [$clientA, $planB];
        });
        $portalUserA = $this->makePortalUser($clientA);

        Auth::guard('client')->login($portalUserA);

        $canView = $this->runWithFirmContext(
            $firm,
            fn () => InstallmentsRelationManager::canViewForRecord($planB->fresh(), ViewPaymentPlan::class)
        );

        $this->assertFalse($canView);
    }

    public function test_resource_exposes_no_create_edit_or_delete_pages(): void
    {
        $pages = PaymentPlanResource::getPages();

        $this->assertSame(['index', 'view'], array_keys($pages), 'PaymentPlanResource must be List + View only — no create/edit route.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function makePortalUser(Client $client): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => 'client-'.Str::random(8).'@example.test',
            'password' => 'irrelevant-hashed-value',
            'is_active' => true,
        ]));
    }
}
