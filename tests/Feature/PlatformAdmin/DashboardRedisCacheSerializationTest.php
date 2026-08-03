<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\Dashboard;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\PlatformRoleService;
use App\Services\PlatformSecurityDashboardService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DashboardRedisCacheSerializationTest — FIRMSVAULT REAL STAGING
 * STABILIZATION, Objective A / Phase 2 (corrected). Regression coverage
 * for the REAL browser-reproduced Platform Admin dashboard HTTP 500
 * ("App\Services\PlatformSecurityDashboardService::recentSecurityEvents():
 * Return value must be of type Illuminate\Support\Collection,
 * __PHP_Incomplete_Class returned").
 *
 * Root cause (confirmed live against the real staging ElastiCache
 * instance, reproduced deterministically): config/cache.php's
 * `serializable_classes` (left at the framework default of `false`)
 * causes Illuminate\Cache\RedisStore::unserialize() to call
 * `unserialize($value, ['allowed_classes' => false])` on every cache
 * READ, silently substituting ANY object class with
 * __PHP_Incomplete_Class instead of throwing. This is NOT a
 * serialization/corruption bug — the underlying cached bytes were
 * proven byte-for-byte valid.
 *
 * CORRECTED approach: `cache.serializable_classes` stays at `false`
 * (arbitrary object deserialization from cache remains disabled, no
 * security control is weakened). The actual fix is at the call site —
 * PlatformSecurityDashboardService::recentSecurityEvents() now caches
 * only a plain scalar array (dates as ISO-8601 strings) and reconstructs
 * the Collection/Carbon values after reading the scalar payload back
 * out, on both the fresh-compute path and every cache-hit path. A
 * scalar array serializes with zero class references, so
 * `serializable_classes` has nothing to reject regardless of its value.
 *
 * These tests run against a REAL local Redis (available in this test
 * environment — see redis-cli ping) with cache.default and
 * session.driver explicitly forced to 'redis', not the array driver
 * phpunit.xml otherwise configures for both, because this exact class
 * of bug is invisible under any non-Redis cache/session driver.
 */
final class DashboardRedisCacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'session.connection' => 'cache',
            'database.redis.options.serializer' => \Redis::SERIALIZER_NONE,
        ]);

        Cache::store('redis')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('redis')->flush();

        parent::tearDown();
    }

    private function roleService(): PlatformRoleService
    {
        return app(PlatformRoleService::class);
    }

    private function activeMfaVerifiedAdmin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(array_merge([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ], $attributes));
    }

    private function rawCachedPayload(int $limit = 10): ?string
    {
        $cacheClient = \Illuminate\Support\Facades\Redis::connection('cache')->client();
        $prefix = config('cache.prefix');
        $fullKey = $prefix.'platform_admin.security_dashboard.recent_security_events.'.$limit;

        $raw = $cacheClient->get($fullKey);

        return is_string($raw) ? $raw : null;
    }

    /**
     * The config-level regression guard: arbitrary object
     * deserialization from cache must remain disabled — the fix must
     * never be "allow more classes," only "cache fewer classes."
     */
    public function test_cache_serializable_classes_remains_false(): void
    {
        $this->assertSame(false, config('cache.serializable_classes'));
    }

    /**
     * Direct proof the raw Redis payload this dashboard writes contains
     * no serialized PHP object at all — a serialized object always
     * begins its type marker with `O:<length>:"<class name>"`; a plain
     * array of scalars never does, regardless of nesting depth.
     */
    public function test_the_cached_redis_payload_contains_no_php_objects(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->create();
        (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::factory()->for($firm)->create()
        );

        app(PlatformSecurityDashboardService::class)->recentSecurityEvents($admin, 10);

        $raw = $this->rawCachedPayload(10);
        $this->assertNotNull($raw, 'Expected a cached payload to exist after a fresh compute.');

        $this->assertMatchesRegularExpression('/^a:\d+:\{/', $raw, 'Cached payload must be a plain serialized array, not an object.');
        $this->assertDoesNotMatchRegularExpression('/O:\d+:"/', $raw, 'Cached payload must contain no serialized PHP object.');

        // Confirm this holds true even under the framework's own
        // strictest deserialization mode — proves serializable_classes
        // staying `false` has nothing to reject here.
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        $this->assertIsArray($decoded);
        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
    }

    /**
     * The exact real service method, called twice in a row — miss then
     * a genuine cache hit — exactly reproducing the live browser
     * failure sequence, and proving no __PHP_Incomplete_Class results
     * from either call.
     */
    public function test_recent_security_events_succeeds_on_both_the_first_request_and_the_cache_hit(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->create();
        (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::factory()->for($firm)->create()
        );

        $service = app(PlatformSecurityDashboardService::class);

        $first = $service->recentSecurityEvents($admin, 10);
        $this->assertInstanceOf(Collection::class, $first);
        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $first);
        $this->assertInstanceOf(Carbon::class, $first->first()['created_at']);

        $second = $service->recentSecurityEvents($admin, 10);
        $this->assertInstanceOf(Collection::class, $second);
        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $second);
        $this->assertInstanceOf(Carbon::class, $second->first()['created_at']);

        $this->assertSame($first->count(), $second->count());
        $this->assertSame(
            $first->first()['created_at']->toIso8601String(),
            $second->first()['created_at']->toIso8601String(),
        );
    }

    /**
     * The actual real-browser route (App\Filament\Pages\Dashboard,
     * mounted at /admin — the panel's default landing page, distinct
     * from the standalone /admin/platform-security-dashboard-page an
     * earlier verification incorrectly relied on exclusively) hit twice
     * in a row must return 200 both times: the first request populates
     * the cache, the second is served from a genuine cache hit — the
     * exact sequence that broke in the real browser.
     */
    public function test_the_real_dashboard_route_returns_200_across_a_cache_miss_and_a_subsequent_cache_hit(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->create();
        (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::factory()->for($firm)->create()
        );

        $this->actingAs($admin, 'platform_admin')
            ->get(Dashboard::getUrl())
            ->assertOk();

        $this->actingAs($admin, 'platform_admin')
            ->get(Dashboard::getUrl())
            ->assertOk();
    }

    /**
     * Redis-backed Livewire hydration: the Dashboard page's own Livewire
     * component (and the widgets it renders, including
     * PlatformRecentPrivilegedActivityWidget, which is what actually
     * calls recentSecurityEvents()) must mount and hydrate successfully
     * with both session.driver and cache.default forced to 'redis' —
     * matching the real deployed staging configuration exactly.
     */
    public function test_dashboard_livewire_component_hydrates_successfully_with_redis_session_and_cache(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->create();
        (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::factory()->for($firm)->create()
        );

        $this->actingAs($admin, 'platform_admin');

        Livewire::test(Dashboard::class)->assertOk();

        // A second, independent component instance simulates the next
        // request's fresh hydrate cycle over the same Redis-backed
        // session/cache state — the real cache-hit path.
        Livewire::test(Dashboard::class)->assertOk();
    }

    /**
     * ReadOnlyAuditor's own gate (PlatformStaffAccessPolicyService::
     * canAccessSecurityLogs()) must remain unaffected by this cache
     * fix — the fix only changes what a successfully-authorized read
     * unserializes to, never who is authorized to read it.
     */
    public function test_read_only_auditor_access_gate_is_unaffected_by_the_cache_fix(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $service = app(PlatformSecurityDashboardService::class);

        $this->expectException(\RuntimeException::class);
        $service->recentSecurityEvents($admin, 10);
    }

    /**
     * Structural guard: the fix must be a genuine root-cause correction,
     * never a broad catch(Throwable) papering over the same class of
     * bug at the page/widget layer.
     */
    public function test_no_broad_throwable_catch_was_added_around_dashboard_rendering(): void
    {
        foreach ([
            app_path('Services/PlatformSecurityDashboardService.php'),
            app_path('Filament/Pages/Dashboard.php'),
            app_path('Filament/Widgets/PlatformRecentPrivilegedActivityWidget.php'),
        ] as $path) {
            $this->assertFileExists($path);

            $source = file_get_contents($path);

            $this->assertStringNotContainsString('catch (\Throwable', $source, "{$path} must not broadly catch Throwable");
            $this->assertStringNotContainsString('catch (Throwable', $source, "{$path} must not broadly catch Throwable");
        }
    }
}
