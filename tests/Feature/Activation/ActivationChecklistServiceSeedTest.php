<?php

namespace Tests\Feature\Activation;

use App\Models\Firm;
use App\Services\ActivationChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ActivationChecklistService::seedProductionReadinessItems() —
 * the one additive method Phase 5 adds to this Phase 1 service.
 * Extends the SAME checklist (approved decision — no second checklist
 * table).
 */
class ActivationChecklistServiceSeedTest extends TestCase
{
    use RefreshDatabase;

    private ActivationChecklistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActivationChecklistService();
    }

    public function test_seeding_requires_an_existing_checklist(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->seedProductionReadinessItems($firm);
    }

    public function test_seeding_inserts_all_twelve_go_live_items_onto_the_existing_checklist(): void
    {
        $firm = Firm::factory()->create();
        $this->service->createChecklist($firm);

        $inserted = $this->service->seedProductionReadinessItems($firm->fresh());

        $this->assertCount(12, $inserted);
        // Section 39A-3L, Checkpoint 2: seedProductionReadinessItems()'s own
        // runWithFirmContext() wrap has already cleared tenant context by the
        // time it returns, so this fresh read of the now-force-protected
        // activation_checklists row genuinely needs its own explicit context.
        $this->assertSame(
            12,
            $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->count())
        );
    }

    public function test_seeding_is_idempotent_and_never_creates_a_duplicate_item_key(): void
    {
        $firm = Firm::factory()->create();
        $this->service->createChecklist($firm);

        $this->service->seedProductionReadinessItems($firm->fresh());
        $secondCall = $this->service->seedProductionReadinessItems($firm->fresh());

        $this->assertEmpty($secondCall);
        // See the previous test's comment: a fresh read of activation_checklists
        // after the service's own context wrap has already cleared needs its
        // own explicit context now that this table is FORCE RLS-protected.
        $this->assertSame(
            12,
            $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->count())
        );
    }

    public function test_seeding_does_not_disturb_pre_existing_phase_1_items(): void
    {
        $firm = Firm::factory()->create();
        $checklist = $this->service->createChecklist($firm);
        $checklist->items()->create([
            'item_key' => 'billing_setup',
            'label' => 'Billing account configured',
            'is_required' => true,
            'is_complete' => true,
        ]);

        $this->service->seedProductionReadinessItems($firm->fresh());

        // See test_seeding_inserts_all_twelve_go_live_items_onto_the_existing_checklist's
        // comment: activation_checklists is now FORCE RLS-protected, so this
        // fresh read (after the service's own wrap has already cleared
        // context) genuinely needs its own explicit context.
        [$itemCount, $billingSetupComplete] = $this->runWithFirmContext($firm, function () use ($firm) {
            $checklist = $firm->fresh()->activationChecklist;

            return [
                $checklist->items()->count(),
                $checklist->items()->where('item_key', 'billing_setup')->first()->is_complete,
            ];
        });

        $this->assertSame(13, $itemCount);
        $this->assertTrue($billingSetupComplete);
    }

    public function test_every_seeded_item_key_matches_the_master_plans_phase_5_scope_list(): void
    {
        $firm = Firm::factory()->create();
        $this->service->createChecklist($firm);

        $this->service->seedProductionReadinessItems($firm->fresh());

        // See test_seeding_inserts_all_twelve_go_live_items_onto_the_existing_checklist's
        // comment: activation_checklists is now FORCE RLS-protected, so this
        // fresh read (after the service's own wrap has already cleared
        // context) genuinely needs its own explicit context.
        $keys = $this->runWithFirmContext(
            $firm,
            fn () => $firm->fresh()->activationChecklist->items()->pluck('item_key')->sort()->values()->all()
        );

        $expected = [
            'ai_mode', 'compliance_acknowledgments', 'consents', 'email_domain',
            'firm_profile', 'jurisdiction', 'payment_mode', 'plan_license',
            'portal', 'practice_areas', 'templates', 'users',
        ];
        sort($expected);

        $this->assertSame($expected, $keys);
    }
}
