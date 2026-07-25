<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\SecurityEvent;
use App\Services\PlatformRoleService;
use App\Services\PlatformSecurityDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformSecurityDashboardServiceTest — Phase 1 correction. Proves the
 * ordering fix for the two confirmed findings against this service:
 *
 *  1. recentSecurityEvents() previously had no tie-breaker anywhere in
 *     its per-firm-loop-then-merge pipeline (neither the per-firm
 *     Firm::query()->get() call nor the per-firm SecurityEvent query
 *     nor the final cross-firm sort), so two events sharing the same
 *     `created_at` second (security_events.created_at has whole-second
 *     precision — see that table's own migration) could render in a
 *     different relative order across otherwise-identical repeated
 *     calls. Fixed via a `created_at DESC, id DESC` total order.
 *  2. adminsWithoutConfirmedMfa()/recentRoleChanges() had the same
 *     class of gap (non-unique `name`, and whole-second `updated_at`
 *     respectively) — fixed the same way.
 *
 * Cache::flush() in setUp() — matching RlsSecurityReportServiceTest's/
 * PlatformExecutiveDashboardServiceTest's own established discipline —
 * CACHE_STORE=array in phpunit.xml persists for the whole test-process
 * lifetime, not per test, so recentSecurityEvents()'s 2-minute
 * Cache::remember() would otherwise leak a prior test's fixtures into
 * this file's own assertions.
 */
final class PlatformSecurityDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function service(): PlatformSecurityDashboardService
    {
        return app(PlatformSecurityDashboardService::class);
    }

    private function securityAuditor(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SecurityAuditor);

        return $admin;
    }

    /**
     * `created_at` is not in SecurityEvent::$fillable (see that model's
     * own docblock — append-only log, deliberately narrow mass-
     * assignment surface), so `factory()->create(['created_at' => ...])`
     * silently drops it and the row would get the real insert-time
     * timestamp instead — worthless for a deliberate tie-break fixture.
     * forceFill() bypasses that guard for this ONE attribute, and
     * because it marks the attribute dirty, Eloquent's own
     * updateTimestamps() (which only fills a timestamp column when it
     * is NOT already dirty) leaves the forced value alone.
     */
    private function createSecurityEventAt(Firm $firm, string $eventType, Carbon $createdAt): SecurityEvent
    {
        return $this->createWithFirmContext($firm, function () use ($firm, $eventType, $createdAt): SecurityEvent {
            $event = SecurityEvent::factory()->forFirm($firm)->make(['event_type' => $eventType]);
            $event->forceFill(['created_at' => $createdAt]);
            $event->save();

            return $event;
        });
    }

    /**
     * `updated_at` is not in PlatformRole::$fillable either — same
     * forceFill() rationale as createSecurityEventAt() above, applied
     * after a normal create() so the row already exists (updateTimestamps()
     * only skips overwriting a dirty timestamp column, so the forceFill()
     * + save() below must happen as a genuine second write).
     */
    private function setRoleUpdatedAt(PlatformRole $role, Carbon $updatedAt): PlatformRole
    {
        $role->forceFill(['updated_at' => $updatedAt])->save();

        return $role->fresh();
    }

    // ------------------------------------------------------------
    // Authorization — unchanged, proven still intact
    // ------------------------------------------------------------

    public function test_recent_security_events_rejects_an_admin_without_the_security_logs_gate(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SalesRep);

        $this->expectException(RuntimeException::class);
        $this->service()->recentSecurityEvents($admin);
    }

    // ------------------------------------------------------------
    // Finding 1: recentSecurityEvents() deterministic tie-breaking
    // ------------------------------------------------------------

    /**
     * Deliberately creates multiple security_events rows across
     * DIFFERENT firms sharing the EXACT SAME created_at second (the
     * real collision this column's precision allows) and asserts the
     * merged, cross-firm result is byte-for-byte identical across
     * repeated calls — proving the (created_at, id) tie-break, not
     * insertion/iteration-order luck, determines the final order.
     */
    public function test_recent_security_events_is_stable_across_repeated_calls_when_timestamps_collide(): void
    {
        $admin = $this->securityAuditor();

        $firmA = Firm::factory()->create(['name' => 'Zeta Firm']);
        $firmB = Firm::factory()->create(['name' => 'Alpha Firm']);
        $tie = now()->startOfSecond();

        foreach ([$firmA, $firmB, $firmA, $firmB] as $i => $firm) {
            $this->createSecurityEventAt($firm, 'tie_event_'.$i, $tie);
        }

        // Distinct, non-tied control event so we know it always sorts first.
        $this->createSecurityEventAt($firmA, 'latest_event', $tie->copy()->addSecond());

        // Cache TTL is 2 minutes — clear between calls so each call is a
        // genuine fresh read, not a reuse of the first call's cached
        // (already-ordered) result. That would trivially "prove" stability
        // without ever re-running the merge/sort logic under test.
        Cache::flush();
        $first = $this->service()->recentSecurityEvents($admin, 10);
        Cache::flush();
        $second = $this->service()->recentSecurityEvents($admin, 10);
        Cache::flush();
        $third = $this->service()->recentSecurityEvents($admin, 10);

        // Compare normalized (created_at cast to an ISO string) rather
        // than raw toArray(): each call hydrates a fresh set of Carbon
        // instances from the database, so assertSame()'s strict `===`
        // would fail on object identity alone even when every value is
        // logically identical. This proves value-level stability, which
        // is what determinism actually means here.
        $normalize = fn ($rows): array => $rows
            ->map(fn (array $row): array => array_merge($row, ['created_at' => $row['created_at']->toISOString()]))
            ->all();

        $this->assertSame($normalize($first), $normalize($second));
        $this->assertSame($normalize($second), $normalize($third));

        // The control event (strictly latest) must lead every call.
        $this->assertSame('latest_event', $first->first()['event_type']);

        // The 4 tied events must appear in descending-id order (the
        // documented tie-break) directly after it, every time.
        $tiedEventTypes = $first->slice(1, 4)->pluck('event_type')->values()->all();
        $this->assertSame(
            ['tie_event_3', 'tie_event_2', 'tie_event_1', 'tie_event_0'],
            $tiedEventTypes,
            'Tied events must sort by id DESC, deterministically, every call.'
        );
    }

    /**
     * The returned row shape is unchanged by the internal `id` sort key
     * — it must never leak into the public return contract.
     */
    public function test_recent_security_events_return_rows_never_expose_the_internal_id_sort_key(): void
    {
        $admin = $this->securityAuditor();
        $firm = Firm::factory()->create();

        $this->createSecurityEventAt($firm, 'login_succeeded', now());

        $rows = $this->service()->recentSecurityEvents($admin);

        $this->assertArrayNotHasKey('id', $rows->first());
        $this->assertSame(
            ['firm_name', 'actor_type', 'actor_id', 'event_type', 'category', 'created_at'],
            array_keys($rows->first())
        );
    }

    public function test_recent_security_events_orders_by_created_at_descending_across_firms(): void
    {
        $admin = $this->securityAuditor();
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->createSecurityEventAt($firmA, 'oldest', now()->subMinutes(10));
        $this->createSecurityEventAt($firmB, 'middle', now()->subMinutes(5));
        $this->createSecurityEventAt($firmA, 'newest', now());

        $rows = $this->service()->recentSecurityEvents($admin, 10);

        $this->assertSame(['newest', 'middle', 'oldest'], $rows->pluck('event_type')->all());
    }

    // ------------------------------------------------------------
    // Finding 3 (sweep): adminsWithoutConfirmedMfa() tie-break
    // ------------------------------------------------------------

    public function test_admins_without_confirmed_mfa_is_stable_when_names_collide(): void
    {
        $this->securityAuditor(); // acting admin, MFA-confirmed by factory default

        $a = PlatformAdmin::factory()->create(['name' => 'Same Name', 'two_factor_confirmed_at' => null]);
        $b = PlatformAdmin::factory()->create(['name' => 'Same Name', 'two_factor_confirmed_at' => null]);

        $first = $this->service()->adminsWithoutConfirmedMfa()->pluck('id')->all();
        $second = $this->service()->adminsWithoutConfirmedMfa()->pluck('id')->all();

        $this->assertSame($first, $second);
        // id tie-break (ascending, matching ->orderBy('id')) — lower id first.
        $expectedOrder = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
        $this->assertSame($expectedOrder, $first);
    }

    // ------------------------------------------------------------
    // Finding 3 (sweep): recentRoleChanges() tie-break
    // ------------------------------------------------------------

    public function test_recent_role_changes_is_stable_when_updated_at_collides(): void
    {
        $this->securityAuditor();

        $tie = now()->startOfSecond();

        $roleA = $this->setRoleUpdatedAt(
            PlatformRole::factory()->create(['role_code' => PlatformRoleCode::BillingAdmin->value]),
            $tie
        );
        $roleB = $this->setRoleUpdatedAt(
            PlatformRole::factory()->create(['role_code' => PlatformRoleCode::SalesRep->value]),
            $tie
        );

        $first = $this->service()->recentRoleChanges()->pluck('id')->all();
        $second = $this->service()->recentRoleChanges()->pluck('id')->all();

        $this->assertSame($first, $second);
        // id DESC tie-break — higher id (roleB, created later) first.
        $expectedOrder = $roleA->id > $roleB->id ? [$roleA->id, $roleB->id] : [$roleB->id, $roleA->id];
        $this->assertSame($expectedOrder, array_values(array_intersect($first, [$roleA->id, $roleB->id])));
    }
}
