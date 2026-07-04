<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\SecurityEvent;
use App\Services\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises BelongsToTenant's "narrow only, never widen" design — see
 * the trait's own doc comment for why no-context-active does not fail
 * closed at the Eloquent layer (that guarantee is designed to live at
 * the RLS layer once enforcement is activated — see
 * RowLevelSecurityPreparationTest and the migration's doc comment).
 */
class BelongsToTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TenantContextResolver();
    }

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }

    public function test_with_no_active_context_all_rows_are_visible(): void
    {
        SecurityEvent::factory()->count(3)->create();

        $this->assertSame(3, SecurityEvent::count());
    }

    public function test_with_active_context_only_own_firm_rows_are_visible(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        SecurityEvent::factory()->count(2)->create(['firm_id' => $firmA->id]);
        SecurityEvent::factory()->count(5)->create(['firm_id' => $firmB->id]);

        $this->resolver->activateForFirm($firmA);

        $this->assertSame(2, SecurityEvent::count());
        $this->assertTrue(SecurityEvent::all()->every(fn ($e) => $e->firm_id === $firmA->id));
    }

    /**
     * The deliberately-broken/unscoped-access-attempt case: code
     * running under firmA's context "forgets" to add its own
     * ->where('firm_id', ...) and queries as if unscoped. The global
     * scope must still prevent firmB's rows from ever appearing.
     */
    public function test_forgotten_explicit_scope_still_cannot_leak_another_firms_rows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        SecurityEvent::factory()->create(['firm_id' => $firmA->id, 'event_type' => 'own_event']);
        SecurityEvent::factory()->create(['firm_id' => $firmB->id, 'event_type' => 'other_firms_event']);

        $this->resolver->activateForFirm($firmA);

        $visibleTypes = SecurityEvent::query()->pluck('event_type')->all();

        $this->assertSame(['own_event'], $visibleTypes);
        $this->assertNotContains('other_firms_event', $visibleTypes);
    }

    public function test_creating_without_explicit_firm_id_autofills_from_active_context(): void
    {
        $firm = Firm::factory()->create();
        $this->resolver->activateForFirm($firm);

        // SecurityEventFactory defaults firm_id to null (platform-level
        // events are legitimate) — an unmodified create() call here
        // relies purely on the BelongsToTenant creating() hook.
        $event = SecurityEvent::factory()->create(['event_type' => 'autofill_test']);

        $this->assertSame($firm->id, $event->fresh()->firm_id);
    }

    public function test_without_tenant_scope_bypasses_active_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        SecurityEvent::factory()->create(['firm_id' => $firmA->id]);
        SecurityEvent::factory()->create(['firm_id' => $firmB->id]);

        $this->resolver->activateForFirm($firmA);

        $this->assertSame(1, SecurityEvent::count());
        $this->assertSame(2, SecurityEvent::withoutTenantScope()->count());
    }

    public function test_for_firm_ignoring_context_returns_only_requested_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        SecurityEvent::factory()->count(2)->create(['firm_id' => $firmA->id]);
        SecurityEvent::factory()->count(3)->create(['firm_id' => $firmB->id]);

        $this->resolver->activateForFirm($firmA);

        $this->assertSame(3, SecurityEvent::forFirmIgnoringContext($firmB->id)->count());
    }

    public function test_assert_belongs_to_active_tenant_passes_for_own_firm_row(): void
    {
        $firm = Firm::factory()->create();
        $event = SecurityEvent::factory()->create(['firm_id' => $firm->id]);

        $this->resolver->activateForFirm($firm);

        $event->assertBelongsToActiveTenant();
        $this->addToAssertionCount(1);
    }

    public function test_assert_belongs_to_active_tenant_throws_for_foreign_firm_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $foreignEvent = SecurityEvent::factory()->create(['firm_id' => $firmB->id]);

        $this->resolver->activateForFirm($firmA);

        $this->expectException(TenantIsolationException::class);

        SecurityEvent::withoutTenantScope()
            ->find($foreignEvent->id)
            ->assertBelongsToActiveTenant();
    }

    public function test_assert_belongs_to_active_tenant_does_not_throw_when_no_context_active(): void
    {
        $event = SecurityEvent::factory()->create();

        $event->assertBelongsToActiveTenant();
        $this->addToAssertionCount(1);
    }
}
