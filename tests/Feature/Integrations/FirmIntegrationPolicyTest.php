<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\FirmUserRole;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Policies\FirmIntegrationPolicy;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmIntegrationPolicyTest — Checkpoint 9 (frozen design §10 item 1).
 * `App\Integrations\Policies\FirmIntegrationPolicy` genuinely exists
 * (the first standard Laravel Policy class in this codebase, per its
 * own docblock) as a SEPARATE, thin class from
 * IntegrationAccessPolicyService — every method here bridges
 * User -> FirmUser via User::activeFirmUser() and then delegates the
 * actual role check to IntegrationAccessPolicyService, plus
 * independently re-confirms the resolved FirmUser's firm_id matches
 * the target FirmIntegration row's firm_id (defense-in-depth, not a
 * substitute for FORCE RLS). This file proves the policy class
 * directly, not merely the service it delegates to.
 */
class FirmIntegrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FirmIntegrationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new FirmIntegrationPolicy(new IntegrationAccessPolicyService(new TimelineEventRecorder()));
    }

    private function actorFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        return $this->createWithFirmContext($firm, fn () => FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]));
    }

    // ------------------------------------------------------------
    // viewAny() / view() — FirmOwner, Attorney, Paralegal, LegalAssistant
    // ------------------------------------------------------------

    public function test_view_any_allows_view_ceiling_roles_and_denies_others(): void
    {
        $firm = Firm::factory()->create();

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant] as $role) {
            $actor = $this->actorFor($firm, $role);
            $this->assertTrue($this->policy->viewAny($actor->user));
        }

        foreach ([FirmUserRole::Receptionist, FirmUserRole::BillingStaff] as $role) {
            $actor = $this->actorFor($firm, $role);
            $this->assertFalse($this->policy->viewAny($actor->user));
        }
    }

    public function test_view_allows_same_firm_view_ceiling_role_and_denies_cross_firm_even_for_an_otherwise_eligible_role(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = $this->createWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create());

        $sameFirmAttorney = $this->actorFor($firmA, FirmUserRole::Attorney);
        $this->assertTrue($this->policy->view($sameFirmAttorney->user, $connectionA));

        $crossFirmAttorney = $this->actorFor($firmB, FirmUserRole::Attorney);
        $this->assertFalse(
            $this->policy->view($crossFirmAttorney->user, $connectionA),
            'A cross-firm actor must be denied even though Attorney is otherwise within the view ceiling — defense-in-depth ownership check.'
        );
    }

    // ------------------------------------------------------------
    // create()/connect() — management tier only
    // ------------------------------------------------------------

    public function test_create_and_connect_are_the_same_check_and_match_the_management_ceiling(): void
    {
        $firm = Firm::factory()->create();

        $owner = $this->actorFor($firm, FirmUserRole::FirmOwner);
        $this->assertTrue($this->policy->create($owner->user));
        $this->assertTrue($this->policy->connect($owner->user));

        $paralegal = $this->actorFor($firm, FirmUserRole::Paralegal);
        $this->assertFalse($this->policy->create($paralegal->user));
        $this->assertFalse($this->policy->connect($paralegal->user));
    }

    // ------------------------------------------------------------
    // update()/configure() — management tier only, same-firm only
    // ------------------------------------------------------------

    public function test_update_and_configure_are_the_same_check_scoped_to_same_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = $this->createWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create());

        $sameFirmOwner = $this->actorFor($firmA, FirmUserRole::FirmOwner);
        $this->assertTrue($this->policy->update($sameFirmOwner->user, $connectionA));
        $this->assertTrue($this->policy->configure($sameFirmOwner->user, $connectionA));

        $crossFirmOwner = $this->actorFor($firmB, FirmUserRole::FirmOwner);
        $this->assertFalse($this->policy->update($crossFirmOwner->user, $connectionA));
        $this->assertFalse($this->policy->configure($crossFirmOwner->user, $connectionA));

        $sameFirmParalegal = $this->actorFor($firmA, FirmUserRole::Paralegal);
        $this->assertFalse($this->policy->update($sameFirmParalegal->user, $connectionA));
    }

    // ------------------------------------------------------------
    // delete()/disconnect() — management tier only, same-firm only
    // ------------------------------------------------------------

    public function test_delete_and_disconnect_are_the_same_check_scoped_to_same_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = $this->createWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create());

        $sameFirmAttorney = $this->actorFor($firmA, FirmUserRole::Attorney);
        $this->assertTrue($this->policy->delete($sameFirmAttorney->user, $connectionA));
        $this->assertTrue($this->policy->disconnect($sameFirmAttorney->user, $connectionA));

        $crossFirmAttorney = $this->actorFor($firmB, FirmUserRole::Attorney);
        $this->assertFalse($this->policy->delete($crossFirmAttorney->user, $connectionA));

        $sameFirmReceptionist = $this->actorFor($firmA, FirmUserRole::Receptionist);
        $this->assertFalse($this->policy->delete($sameFirmReceptionist->user, $connectionA));
    }

    // ------------------------------------------------------------
    // No active FirmUser -> always denied
    // ------------------------------------------------------------

    public function test_a_user_with_no_active_firm_user_is_denied_every_gate(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $orphanUser = \App\Models\User::factory()->create();

        $this->assertFalse($this->policy->viewAny($orphanUser));
        $this->assertFalse($this->policy->view($orphanUser, $connection));
        $this->assertFalse($this->policy->create($orphanUser));
        $this->assertFalse($this->policy->update($orphanUser, $connection));
        $this->assertFalse($this->policy->delete($orphanUser, $connection));
    }
}
