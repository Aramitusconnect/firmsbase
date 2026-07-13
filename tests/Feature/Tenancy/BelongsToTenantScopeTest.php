<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\SecurityEvent;
use App\Services\TenantContextResolver;
use App\Services\TenantContextService;
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
        // security_events has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3L, Phase B6, Checkpoint 34) — PHP-memory context alone no
        // longer determines what's actually readable; the DB-session
        // context must independently match the firm being queried.
        (new TenantContextService())->setDatabaseTenantContextForFirmId($firmA->id);

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
        // security_events has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3L, Phase B6, Checkpoint 34) — the DB-session context must
        // independently match the firm being queried, matching the
        // activated PHP-memory firm here.
        (new TenantContextService())->setDatabaseTenantContextForFirmId($firmA->id);

        $visibleTypes = SecurityEvent::query()->pluck('event_type')->all();

        $this->assertSame(['own_event'], $visibleTypes);
        $this->assertNotContains('other_firms_event', $visibleTypes);
    }

    /**
     * Rewritten for Section 39A-3L, Phase B6, Checkpoint 34 (security_events
     * FORCE ROW LEVEL SECURITY activation). This test genuinely tests the
     * BelongsToTenant creating() autofill hook, which must be exercised
     * directly rather than through SecurityEvent::factory()->create():
     * the factory's context-hold create() override (transplanted from
     * BackupRestoreTestFactory) groups bare-created models by firm_id
     * read from the in-memory model built by make() — which happens
     * BEFORE store() is called, while creating() only fires DURING
     * store()/save(). At grouping time firm_id is still the factory
     * definition's raw null default, so the override commits to
     * clearDatabaseTenantContext() for that group before store() runs,
     * and only then does creating() autofill firm_id to the real,
     * non-null active firm — producing an INSERT with firm_id = <real
     * firm> but DB-session context already cleared to empty, which the
     * new WITH CHECK rejects outright. Bypassing the factory and calling
     * SecurityEvent::create() directly with DB context pre-set avoids
     * this ordering conflict and tests exactly what this test claims to
     * test: the trait's own autofill hook.
     */
    public function test_creating_without_explicit_firm_id_autofills_from_active_context(): void
    {
        $firm = Firm::factory()->create();
        $this->resolver->activateForFirm($firm);
        (new TenantContextService())->setDatabaseTenantContextForFirmId($firm->id);

        $event = SecurityEvent::create(['event_type' => 'autofill_test', 'actor_type' => 'User', 'category' => 'authentication']);

        $this->assertSame($firm->id, $event->fresh()->firm_id);
    }

    public function test_without_tenant_scope_bypasses_active_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        SecurityEvent::factory()->create(['firm_id' => $firmA->id]);
        SecurityEvent::factory()->create(['firm_id' => $firmB->id]);

        $this->resolver->activateForFirm($firmA);
        (new TenantContextService())->setDatabaseTenantContextForFirmId($firmA->id);

        $this->assertSame(1, SecurityEvent::count());

        // Post-FORCE, withoutTenantScope() only removes the ORM-layer WHERE —
        // it can no longer surface another firm's rows, because RLS is the
        // real enforcement boundary now and is untouched by removing an
        // Eloquent scope. Deliberate change from pre-FORCE behavior, not a
        // regression: this is RLS providing real defense-in-depth even when
        // app-layer scope is mistakenly/deliberately removed. A Postgres
        // session only ever has one app.current_firm_id, and this table's
        // read policy has no "visible to every firm" branch for real
        // firm-owned rows, so withoutTenantScope() can no longer see both
        // firms' rows at once the way it could before FORCE was activated.
        $this->assertSame(1, SecurityEvent::withoutTenantScope()->count());
    }

    public function test_for_firm_ignoring_context_returns_only_requested_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        SecurityEvent::factory()->count(2)->create(['firm_id' => $firmA->id]);
        SecurityEvent::factory()->count(3)->create(['firm_id' => $firmB->id]);

        $this->resolver->activateForFirm($firmA);
        // forFirmIgnoringContext() only removes Eloquent's own scope — it
        // has zero effect on the RLS session setting. The DB session must
        // match the firm actually QUERIED (firmB), not the activated
        // PHP-memory firm (firmA), or RLS filters the result to 0
        // regardless of the explicit WHERE firm_id = firmB clause.
        (new TenantContextService())->setDatabaseTenantContextForFirmId($firmB->id);

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
        // DB-session context must stay on firmB (the row's real firm) so the
        // row is actually readable under RLS; the PHP-memory mismatch (firmA
        // active vs. firmB owning the row) is what's under test, not
        // visibility.
        (new TenantContextService())->setDatabaseTenantContextForFirmId($firmB->id);

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
