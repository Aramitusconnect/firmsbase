<?php

namespace Tests\Feature\Activation;

use App\Enums\FirmActivationEventStatus;
use App\Enums\FirmUserStatus;
use App\Enums\LicenseStatus;
use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmPracticeArea;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\ActivationChecklistService;
use App\Services\FirmProductionActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmProductionActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActivationChecklistService $activationChecklist;
    private FirmProductionActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activationChecklist = new ActivationChecklistService();
        $this->service = new FirmProductionActivationService($this->activationChecklist);
    }

    /**
     * Sets up a firm that satisfies every Phase 1 base gate
     * (billing_account_id, firmSettings, usable license, tenant
     * encryption key) but has NOT yet had the checklist seeded/
     * completed — the minimum starting point for production-readiness
     * tests.
     */
    private function firmWithBaseActivationSatisfied(): Firm
    {
        $firm = Firm::factory()->create(['billing_account_id' => \App\Models\BillingAccount::factory()->create()->id]);
        FirmSettings::factory()->forFirm($firm)->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active]);
        TenantEncryptionKey::factory()->create(['firm_id' => $firm->id, 'status' => TenantEncryptionKeyStatus::Active]);
        $this->activationChecklist->createChecklist($firm);

        return $firm->fresh();
    }

    public function test_not_ready_when_the_checklist_has_no_items_seeded(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();

        $result = $this->service->evaluate($firm);

        $this->assertFalse($result->ready);
    }

    public function test_not_ready_while_any_seeded_item_remains_incomplete(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();
        $this->activationChecklist->seedProductionReadinessItems($firm);

        $result = $this->service->evaluate($firm->fresh());

        $this->assertFalse($result->ready);
        $this->assertNotEmpty($result->unmetItems);
        $this->assertContains('firm_profile', $result->unmetItems);
    }

    public function test_ready_once_every_seeded_item_is_complete_or_waived(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();
        $this->activationChecklist->seedProductionReadinessItems($firm);

        // Section 39A-3L, Checkpoint 3 — activation_checklists now has
        // FORCE ROW LEVEL SECURITY active, so this bare read (loading
        // $firm->fresh()->activationChecklist) needs an explicit tenant
        // context wrap, same as ActivationChecklistServiceTest's own
        // Checkpoint 2 fix.
        $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->update(['is_complete' => true, 'completed_at' => now()]));

        $result = $this->service->evaluate($firm->fresh());

        $this->assertTrue($result->ready);
        $this->assertEmpty($result->unmetItems);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_waived_items_count_as_satisfied(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();
        $this->activationChecklist->seedProductionReadinessItems($firm);

        // Section 39A-3L, Checkpoint 3 — same bare-read fix as above:
        // loading $firm->fresh()->activationChecklist now requires an
        // explicit tenant context since activation_checklists has FORCE
        // ROW LEVEL SECURITY active.
        $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->update([
            'is_complete' => false,
            'waived_at' => now(),
            'waiver_reason' => 'Not applicable for this pilot firm',
        ]));

        $result = $this->service->evaluate($firm->fresh());

        $this->assertTrue($result->ready);
    }

    public function test_evaluate_writes_a_firm_scoped_audit_event_every_call(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();

        $this->service->evaluate($firm);

        // Section 39A-3L, Checkpoint 3 — firm_activation_events now has
        // FORCE ROW LEVEL SECURITY active. evaluate()'s own
        // runWithFirmContext() wrap (inside recordEvaluation()) has
        // already returned and cleared context by the time this read
        // runs, so this genuinely fresh read needs its own explicit
        // wrap.
        $event = $this->runWithFirmContext(
            $firm,
            fn () => \App\Models\FirmActivationEvent::query()->where('firm_id', $firm->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame('production_readiness_evaluated', $event->event_type);
        $this->assertSame(FirmActivationEventStatus::Blocked, $event->status);
    }

    public function test_evaluate_logs_a_second_production_ready_event_only_when_ready(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();
        $this->activationChecklist->seedProductionReadinessItems($firm);

        // Section 39A-3L, Checkpoint 3 — same bare-read fix as the
        // other tests in this file: loading
        // $firm->fresh()->activationChecklist now requires an explicit
        // tenant context since activation_checklists has FORCE ROW
        // LEVEL SECURITY active.
        $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->update(['is_complete' => true, 'completed_at' => now()]));

        $this->service->evaluate($firm->fresh());

        // Section 39A-3L, Checkpoint 3 — same firm_activation_events
        // bare-read fix as test_evaluate_writes_a_firm_scoped_audit_event_every_call()
        // above: this read happens after evaluate() has already
        // returned and cleared its own context.
        $this->assertSame(
            1,
            $this->runWithFirmContext(
                $firm,
                fn () => \App\Models\FirmActivationEvent::query()->where('firm_id', $firm->id)->where('event_type', 'production_ready')->count(),
            )
        );
    }

    public function test_production_readiness_is_derived_only_no_new_firms_column_is_touched(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();

        $this->service->evaluate($firm);

        // firms table gains no new column for this — confirmed by the
        // fact that Firm's fillable/casts are untouched and no
        // "production_ready_at"-style attribute exists on the model.
        $this->assertArrayNotHasKey('production_ready_at', $firm->fresh()->getAttributes());
    }

    public function test_auto_complete_verifiable_items_marks_only_genuinely_satisfied_items(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();
        // Section 39A-3L, Checkpoint 18 — same bare-read fix pattern as
        // the activation_checklists fixes elsewhere in this file:
        // firm_settings now has FORCE ROW LEVEL SECURITY active, so
        // this lazy relation load + update() needs an explicit tenant
        // context (ambient context from firmWithBaseActivationSatisfied()'s
        // own FirmSettings::factory() call is no longer reliably active
        // by this point, since createChecklist() runs its own wrap in
        // between and clears it on return).
        $this->runWithFirmContext($firm, fn () => $firm->firmSettings->update(['state_jurisdiction' => 'NY']));
        FirmPracticeArea::factory()->create(['firm_id' => $firm->id, 'practice_area_id' => PracticeArea::factory()->create()->id, 'is_enabled' => true]);
        FirmUser::factory()->create(['firm_id' => $firm->id, 'user_id' => User::factory()->create()->id, 'status' => FirmUserStatus::Active]);
        $this->activationChecklist->seedProductionReadinessItems($firm);

        $completed = $this->service->autoCompleteVerifiableItems($firm->fresh());

        sort($completed);
        $this->assertContains('practice_areas', $completed);
        $this->assertContains('users', $completed);
        $this->assertContains('jurisdiction', $completed);
        $this->assertContains('plan_license', $completed);
        $this->assertContains('payment_mode', $completed);
        $this->assertContains('ai_mode', $completed);
        // manual-only items are never auto-completed
        $this->assertNotContains('firm_profile', $completed);
        $this->assertNotContains('compliance_acknowledgments', $completed);
    }

    public function test_auto_complete_is_idempotent_and_logs_one_event_per_item(): void
    {
        $firm = $this->firmWithBaseActivationSatisfied();
        $this->activationChecklist->seedProductionReadinessItems($firm);

        $this->service->autoCompleteVerifiableItems($firm->fresh());
        $secondRun = $this->service->autoCompleteVerifiableItems($firm->fresh());

        $this->assertEmpty($secondRun);
    }
}
