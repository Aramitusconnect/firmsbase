<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\FirmUserRole;
use App\Enums\PaymentPlanStatus;
use App\Filament\Firm\Resources\PaymentPlanResource;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\ActivatePaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\CancelPaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\CreatePaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\MarkPaymentPlanDefaultedAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\RenegotiatePaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Pages\ListPaymentPlans;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PaymentPlan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PaymentPlanResourceAccessTest — Firm Feature Manifest §6 Tier 2.
 * Proves role ceilings, that every named Action calls the real
 * PaymentPlanService method, that "Renegotiate" produces a brand new
 * PaymentPlan row rather than mutating the existing one, that
 * "Mark Defaulted" requires an explicit reason + confirmation, and the
 * small RLS regression checklist required for this module.
 */
final class PaymentPlanResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_list_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListPaymentPlans::class));

        $test->assertSuccessful();
    }

    // ------------------------------------------------------------
    // Role ceilings
    // ------------------------------------------------------------

    public function test_create_action_is_visible_for_firm_owner_attorney_and_billing_staff(): void
    {
        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListPaymentPlans::class));
            $test->assertActionVisible(CreatePaymentPlanAction::getDefaultName());
        }
    }

    public function test_create_action_is_hidden_for_paralegal_legal_assistant_and_receptionist(): void
    {
        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListPaymentPlans::class));
            $test->assertActionHidden(CreatePaymentPlanAction::getDefaultName());
        }
    }

    public function test_billing_staff_cannot_activate_renegotiate_cancel_or_mark_defaulted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $draftPlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());
        $activePlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create());

        $this->runWithFirmContext($firm, function () use ($draftPlan, $activePlan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->assertTableActionHidden(ActivatePaymentPlanAction::getDefaultName(), $draftPlan);
            $test->assertTableActionHidden(RenegotiatePaymentPlanAction::getDefaultName(), $activePlan);
            $test->assertTableActionHidden(CancelPaymentPlanAction::getDefaultName(), $activePlan);
            $test->assertTableActionHidden(MarkPaymentPlanDefaultedAction::getDefaultName(), $activePlan);
        });
    }

    public function test_firm_owner_can_see_activate_renegotiate_cancel_and_mark_defaulted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $draftPlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());
        $activePlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create());

        $this->runWithFirmContext($firm, function () use ($draftPlan, $activePlan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->assertTableActionVisible(ActivatePaymentPlanAction::getDefaultName(), $draftPlan);
            $test->assertTableActionVisible(RenegotiatePaymentPlanAction::getDefaultName(), $activePlan);
            $test->assertTableActionVisible(CancelPaymentPlanAction::getDefaultName(), $activePlan);
            $test->assertTableActionVisible(MarkPaymentPlanDefaultedAction::getDefaultName(), $activePlan);
        });
    }

    // ------------------------------------------------------------
    // Actions hidden when status doesn't allow the transition
    // ------------------------------------------------------------

    public function test_activate_is_hidden_once_already_active(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create());

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->assertTableActionHidden(ActivatePaymentPlanAction::getDefaultName(), $plan);
        });
    }

    public function test_renegotiate_is_hidden_for_a_draft_plan(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->assertTableActionHidden(RenegotiatePaymentPlanAction::getDefaultName(), $plan);
        });
    }

    public function test_cancel_is_hidden_once_completed(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create(['status' => PaymentPlanStatus::Completed]));

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->assertTableActionHidden(CancelPaymentPlanAction::getDefaultName(), $plan);
        });
    }

    // ------------------------------------------------------------
    // Actions really call the matching PaymentPlanService method
    // ------------------------------------------------------------

    public function test_create_action_creates_a_plan_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->mountAction(CreatePaymentPlanAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'matter_id' => null,
                'invoice_id' => null,
                'installments' => [
                    ['amount' => 100, 'due_at' => now()->addMonth()->toDateString()],
                    ['amount' => 100, 'due_at' => now()->addMonths(2)->toDateString()],
                ],
            ]);
            $test->callMountedAction();
            $test->assertNotified('Payment plan created');
        });

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($plan);
        $this->assertSame(PaymentPlanStatus::Draft, $plan->status);
        $this->assertSame(20000, $plan->total_cents);
        $this->assertSame(2, $plan->installment_count);
        $this->assertSame(2, $this->runWithFirmContext($firm, fn () => $plan->installments()->count()));
    }

    public function test_activate_transitions_the_plan_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->callTableAction(ActivatePaymentPlanAction::getDefaultName(), $plan);
        });

        $fresh = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(PaymentPlanStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->activated_at);
    }

    public function test_cancel_transitions_the_plan_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create());

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->mountTableAction(CancelPaymentPlanAction::getDefaultName(), $plan);
            $test->setActionData(['reason' => 'Client requested cancellation']);
            $test->callMountedTableAction();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(PaymentPlanStatus::Cancelled, $fresh->status);
    }

    public function test_mark_defaulted_requires_a_reason_and_transitions_the_plan_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create());

        // Missing reason must fail validation (reason is required()).
        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->mountTableAction(MarkPaymentPlanDefaultedAction::getDefaultName(), $plan);
            $test->setActionData(['reason' => '']);
            $test->callMountedTableAction();
            $test->assertHasTableActionErrors(['reason']);
        });

        $this->assertSame(PaymentPlanStatus::Active, $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id)->status));

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->mountTableAction(MarkPaymentPlanDefaultedAction::getDefaultName(), $plan);
            $test->setActionData(['reason' => 'Three consecutive missed installments, firm-confirmed']);
            $test->callMountedTableAction();
            $test->assertNotified('Payment plan marked defaulted');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(PaymentPlanStatus::Defaulted, $fresh->status);
        $this->assertNotNull($fresh->defaulted_at);
    }

    // ------------------------------------------------------------
    // Renegotiate creates a NEW PaymentPlan row (dedicated test)
    // ------------------------------------------------------------

    public function test_renegotiate_creates_a_new_payment_plan_row_rather_than_mutating_the_existing_one(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create(['total_cents' => 20000, 'installment_count' => 2]));

        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->count()));

        $this->runWithFirmContext($firm, function () use ($plan): void {
            $test = Livewire::test(ListPaymentPlans::class);
            $test->mountTableAction(RenegotiatePaymentPlanAction::getDefaultName(), $plan);
            $test->setActionData([
                'installments' => [
                    ['amount' => 50, 'due_at' => now()->addMonth()->toDateString()],
                ],
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Payment plan renegotiated');
        });

        // A SECOND row now exists — the old plan's own primary key is
        // unchanged and untouched except for its status/renegotiated_at.
        $this->assertSame(2, $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->count()));

        $oldPlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(PaymentPlanStatus::Renegotiated, $oldPlan->status);
        $this->assertNotNull($oldPlan->renegotiated_at);
        // The old plan's own total/installments are UNCHANGED — proves
        // this was never an in-place edit.
        $this->assertSame(20000, $oldPlan->total_cents);
        $this->assertSame(2, $oldPlan->installment_count);

        $newPlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->where('supersedes_payment_plan_id', $plan->id)->first());
        $this->assertNotNull($newPlan, 'Renegotiate must create a new PaymentPlan row with supersedes_payment_plan_id set to the old plan.');
        $this->assertNotSame($plan->id, $newPlan->id);
        $this->assertSame(PaymentPlanStatus::Active, $newPlan->status);
        $this->assertSame(5000, $newPlan->total_cents);
        $this->assertSame(1, $newPlan->installment_count);
        $this->assertSame($plan->client_id, $newPlan->client_id);
    }

    // ------------------------------------------------------------
    // Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    public function test_a_firm_user_can_access_its_own_payment_plans(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(PaymentPlanResource::getUrl('view', ['record' => $plan])));

        $response->assertSuccessful();
    }

    public function test_list_page_shows_only_this_firms_payment_plans(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListPaymentPlans::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$planA]);
        $test->assertCanNotSeeTableRecords([$planB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_payment_plan_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('payment_plans')->pluck('id')->all());

        $this->assertContains($planA->id, $visibleIds);
        $this->assertNotContains($planB->id, $visibleIds, "Firm A's session must never read Firm B's payment plan row.");
    }

    public function test_direct_url_guess_of_another_firms_payment_plan_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(PaymentPlanResource::getUrl('view', ['record' => $planB])));

        $response->assertNotFound();
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
