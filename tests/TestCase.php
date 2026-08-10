<?php

namespace Tests;

use App\Models\Firm;
use App\Services\CanonicalUrlService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * CHECKPOINT 1 addition (FirmsVault Live Integrations,
     * checkpoint1-design-health-sandbox.md §B.3,
     * checkpoint1-combined-design.md §2.3): global, suite-wide guard so
     * ANY test anywhere — Integration-domain or not — that attempts a
     * real, un-faked outbound HTTP call through Laravel's Http facade
     * fails loudly instead of silently attempting (or appearing to
     * succeed against) a real network destination.
     * `Http::preventStrayRequests()` composes cleanly with `Http::fake()`
     * — it only trips on a request that does not match any registered
     * fake rule, so every existing/future test that already calls
     * `Http::fake([...])` is unaffected. This is additive: the class had
     * no setUp() override before this checkpoint.
     *
     * Residual, disclosed gap (not solved here): a test extending bare
     * PHPUnit\Framework\TestCase directly (never booting the framework)
     * cannot reach this guard, since it never resolves the Http facade
     * at all — see NoRealNetworkCallTest's own forbidden-primitive scan
     * for the complementary, framework-independent defense against raw
     * curl_/fsockopen/file_get_contents('http...) usage.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Section 39A-2 — RLS context rollout test helpers. Pure additions
     * (no setUp/tearDown hook, no global behavior change for any
     * existing test that does not call them) so any test can opt in to
     * running its tenant-owned reads/writes under the real PostgreSQL
     * app.current_firm_id setting the RLS policies read, in
     * preparation for a future FORCE ROW LEVEL SECURITY activation
     * branch. All four delegate to (or directly verify) the existing
     * TenantContextService — no second context mechanism is
     * introduced here.
     */

    /**
     * Runs $callback with the given firm's tenant context active for
     * both the PHP-memory layer and the PostgreSQL session/transaction
     * setting — context is always cleared afterward, even if the
     * callback throws (see TenantContextService::runWithFirmContext()).
     */
    protected function runWithFirmContext(Firm|int|string $firm, callable $callback): mixed
    {
        return (new TenantContextService)->runWithFirmContext($firm, $callback);
    }

    /**
     * Semantically the same mechanism as runWithFirmContext() — named
     * separately for the common "create a tenant-owned fixture row
     * under explicit context" pattern, so test code reads as intent
     * rather than mechanism.
     */
    protected function createWithFirmContext(Firm|int|string $firm, callable $callback): mixed
    {
        return $this->runWithFirmContext($firm, $callback);
    }

    /**
     * Asserts no PostgreSQL tenant context is currently active —
     * app.current_firm_id evaluates to NULL/empty, exactly the state
     * the RLS policies treat as "fail closed, no rows match."
     */
    protected function assertNoDatabaseTenantContext(string $message = ''): void
    {
        $value = $this->currentDatabaseTenantContextValue();

        $this->assertTrue(
            $value === null || $value === '',
            $message !== '' ? $message : 'Expected no PostgreSQL tenant context to be active, but app.current_firm_id is set to \''.$value.'\'.'
        );
    }

    /**
     * Asserts the currently active PostgreSQL tenant context matches
     * the given firm exactly.
     */
    protected function assertDatabaseTenantContextIs(Firm|int $firm, string $message = ''): void
    {
        $expectedFirmId = $firm instanceof Firm ? $firm->id : $firm;
        $value = $this->currentDatabaseTenantContextValue();

        $this->assertSame(
            (string) $expectedFirmId,
            $value,
            $message !== '' ? $message : "Expected PostgreSQL tenant context to be '{$expectedFirmId}', but found '{$value}'."
        );
    }

    private function currentDatabaseTenantContextValue(): ?string
    {
        return DB::selectOne("select current_setting('app.current_firm_id', true) as value")->value;
    }

    /**
     * Mission 1 (canonical reconstruction — Domain & Security Boundary
     * Architecture) test helpers — absolute URLs on each canonical
     * hostname, for use with $this->get()/->post()/etc. Laravel's HTTP
     * test client performs no real network lookup for an absolute URL;
     * it only sets the in-process request's Host header, so these never
     * depend on public or local DNS. Reading through CanonicalUrlService
     * (the same authority the application itself uses) rather than
     * hardcoding a hostname here keeps tests correct if the
     * config/hosts.php defaults ever change.
     */
    protected function marketingUrl(string $path = ''): string
    {
        return app(CanonicalUrlService::class)->marketingUrl().$path;
    }

    protected function firmAppUrl(string $path = ''): string
    {
        return app(CanonicalUrlService::class)->firmAppUrl().$path;
    }

    protected function clientPortalUrl(string $path = ''): string
    {
        return app(CanonicalUrlService::class)->clientPortalUrl().$path;
    }

    protected function adminUrl(string $path = ''): string
    {
        return app(CanonicalUrlService::class)->adminUrl().$path;
    }

    protected function myAttorneyUrl(string $path = ''): string
    {
        return app(CanonicalUrlService::class)->myAttorneyUrl().$path;
    }
}
