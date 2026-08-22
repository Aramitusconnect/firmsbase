<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\UnknownProviderException;
use App\Integrations\Providers\TestProvider\TestProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TestProviderProductionIsolationTest — Checkpoint 14 narrow production
 * change (production-diff-review.md §"TestProvider guard";
 * frozen-documentation-rollout-plan.md §2 row 3).
 *
 * Proves the AND-narrowing security fix applied at both of
 * TestProvider's independent gates:
 *
 *   config/integrations.php:
 *     env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false) && ! app()->environment('production')
 *
 *   TestProvider::isEnabledByEnvironment() (app/Integrations/Providers/
 *   TestProvider/TestProvider.php):
 *     filter_var(env(...), FILTER_VALIDATE_BOOLEAN) && ! app()->environment('production')
 *
 * Before this checkpoint, both gates checked ONLY the env flag — a
 * single wrongly-set INTEGRATIONS_TEST_PROVIDER_ENABLED=true in a real
 * production environment would have registered TestProvider and made
 * it resolvable. This is a pure AND-narrowing change: the ONLY cell in
 * the (flag, environment) truth table whose behavior changes is
 * (flag=true, environment=production) — enabled -> disabled. Every
 * other cell is unchanged. This test class proves exactly that truth
 * table, at both the gate-function level and the real
 * ProviderRegistry::get() resolution level a caller would actually
 * observe.
 *
 * Follows this repo's established environment-simulation precedent —
 * app()->detectEnvironment(fn () => '...') — used identically by
 * tests/Feature/Security/SeedData/DatabaseSeederSafetyTest.php
 * (test_database_seeder_does_not_run_outside_local_or_testing()) to
 * simulate a non-testing running environment inside a fully booted
 * Laravel TestCase. Every test that mutates the detected environment
 * restores it to 'testing' (this suite's real phpunit.xml APP_ENV) in
 * a finally block, exactly like that precedent, so no test leaks
 * environment state into a sibling test.
 *
 * Follows tests/Unit/Integrations/TestProviderStubTest.php's
 * established env-flag-mutation precedent (setEnvFlag()/tearDown()
 * snapshot-and-restore across getenv()/$_ENV/$_SERVER) for driving the
 * INTEGRATIONS_TEST_PROVIDER_ENABLED flag itself, and
 * tests/Unit/Integrations/ProviderRegistryTest.php's precedent
 * (extends the full Laravel TestCase for app()->make()/config()
 * resolution, deliberately never uses RefreshDatabase/
 * DatabaseMigrations/factories) since this file also issues zero
 * database queries — only config/environment state and the
 * ProviderRegistry container-resolution path are exercised.
 */
final class TestProviderProductionIsolationTest extends TestCase
{
    private const ENV_FLAG = 'INTEGRATIONS_TEST_PROVIDER_ENABLED';

    /** @var string|false original getenv() value, to restore in tearDown(). */
    private string|false $originalGetenv;

    private mixed $originalEnvSuperglobal;

    private mixed $originalServerSuperglobal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalGetenv = getenv(self::ENV_FLAG);
        $this->originalEnvSuperglobal = $_ENV[self::ENV_FLAG] ?? null;
        $this->originalServerSuperglobal = $_SERVER[self::ENV_FLAG] ?? null;

        // Sanity precondition, matching TestProviderStubTest's own
        // convention: this suite runs under phpunit.xml's
        // APP_ENV=testing, so the "no simulation applied yet" baseline
        // must genuinely be non-production before any test starts
        // asserting behavior that depends on it.
        $this->assertTrue(app()->environment('testing'), 'This test suite assumes it runs under APP_ENV=testing (phpunit.xml) before any environment simulation is applied.');
    }

    protected function tearDown(): void
    {
        // Defensive — every test that calls detectEnvironment() below
        // restores it itself in a finally block, but this guarantees no
        // leakage into a sibling test file even if a test fails before
        // reaching its own restore.
        app()->detectEnvironment(fn () => 'testing');

        if ($this->originalGetenv === false) {
            putenv(self::ENV_FLAG);
        } else {
            putenv(self::ENV_FLAG.'='.$this->originalGetenv);
        }

        if ($this->originalEnvSuperglobal === null) {
            unset($_ENV[self::ENV_FLAG]);
        } else {
            $_ENV[self::ENV_FLAG] = $this->originalEnvSuperglobal;
        }

        if ($this->originalServerSuperglobal === null) {
            unset($_SERVER[self::ENV_FLAG]);
        } else {
            $_SERVER[self::ENV_FLAG] = $this->originalServerSuperglobal;
        }

        parent::tearDown();
    }

    private function setEnvFlag(?string $value): void
    {
        if ($value === null) {
            putenv(self::ENV_FLAG);
            unset($_ENV[self::ENV_FLAG], $_SERVER[self::ENV_FLAG]);

            return;
        }

        putenv(self::ENV_FLAG.'='.$value);
        $_ENV[self::ENV_FLAG] = $value;
        $_SERVER[self::ENV_FLAG] = $value;
    }

    /**
     * Invokes the private isEnabledByEnvironment() gate directly via
     * reflection — the requirement this checkpoint's rollout plan
     * names explicitly, alongside the config('integrations.providers')
     * gate. isConfigured() (public) simply delegates to this same
     * method 1:1 (see TestProvider.php:266-268), so exercising both
     * gives independent confirmation neither the private method nor
     * its public delegate silently diverges.
     */
    private function isEnabledByEnvironment(TestProvider $provider): bool
    {
        $method = new ReflectionMethod(TestProvider::class, 'isEnabledByEnvironment');
        $method->setAccessible(true);

        return $method->invoke($provider);
    }

    /**
     * Re-requires the real config/integrations.php file directly
     * (bypassing the cached config() helper, which only reflects
     * whatever the environment/env-var state was at application boot)
     * so each assertion below exercises the actual, currently
     * unmutated production file under the CURRENT flag/environment
     * state — exactly TestProviderStubTest's established convention
     * for this same file.
     */
    private function requireConfigFile(): array
    {
        return require config_path('integrations.php');
    }

    // ------------------------------------------------------------
    // 1. POSITIVE REGRESSION — flag=true, environment=production.
    // This is the actual bug being fixed: before this checkpoint, both
    // gates would have enabled TestProvider here. Prove they no longer
    // do, at the gate-function level AND at the real
    // ProviderRegistry::get() resolution level a caller would observe.
    // ------------------------------------------------------------

    public function test_flag_true_in_production_disables_is_enabled_by_environment(): void
    {
        $this->setEnvFlag('true');

        try {
            app()->detectEnvironment(fn () => 'production');
            $this->assertTrue(app()->environment('production'), 'Sanity check: environment simulation must genuinely report production before proceeding.');

            $provider = new TestProvider;

            $this->assertFalse(
                $this->isEnabledByEnvironment($provider),
                'isEnabledByEnvironment() must return false when the env flag is true but the running environment is production — this is the exact case Checkpoint 14 closes.'
            );
            $this->assertFalse(
                $provider->isConfigured(),
                'isConfigured() (the public delegate) must agree with isEnabledByEnvironment().'
            );
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_flag_true_in_production_does_not_register_test_provider_in_config_file(): void
    {
        $this->setEnvFlag('true');

        try {
            app()->detectEnvironment(fn () => 'production');
            $this->assertTrue(app()->environment('production'));

            $config = $this->requireConfigFile();

            $this->assertArrayHasKey(ProviderKey::Test->value, $config['providers']);
            $this->assertNull(
                $config['providers'][ProviderKey::Test->value],
                'config/integrations.php must map the Test provider key to null (unregistered) when the flag is true but the environment is production — never TestProvider::class.'
            );
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_flag_true_in_production_provider_registry_throws_unknown_provider_when_resolving_via_the_real_config_shape(): void
    {
        // The runtime-faithful proof (ProviderRegistry.php:99, per
        // production-diff-review.md): rather than testing the gate
        // functions in isolation, this reproduces the exact narrowed
        // expression config/integrations.php now evaluates directly
        // into Config::set('integrations.providers', ...) — mirroring
        // exactly what the real config file computes under this
        // (flag=true, environment=production) state — and then
        // resolves through the real ProviderRegistry a caller (e.g.
        // ConnectProviderAction, SyncRetryPollJob) would actually use.
        // This is the level a real caller observes: "is TestProvider
        // resolvable at all," not merely "what does one gate function
        // return in a vacuum."
        $this->setEnvFlag('true');

        try {
            app()->detectEnvironment(fn () => 'production');
            $this->assertTrue(app()->environment('production'));

            config([
                'integrations.providers' => [
                    ProviderKey::Test->value => env(self::ENV_FLAG, false) && ! app()->environment('production')
                        ? TestProvider::class
                        : null,
                ],
            ]);

            $this->expectException(UnknownProviderException::class);

            app(ProviderRegistry::class)->get(ProviderKey::Test);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_flag_true_in_production_provider_registry_has_returns_false_and_all_excludes_it(): void
    {
        // Companion non-throwing proof at the same registry-resolution
        // level: ProviderRegistry::has() (the non-throwing existence
        // check ConnectProviderAction's dropdown filter relies on) and
        // ::all() (the platform admin overview's provider listing) must
        // both agree TestProvider is absent, not merely that get()
        // throws.
        $this->setEnvFlag('true');

        try {
            app()->detectEnvironment(fn () => 'production');

            config([
                'integrations.providers' => [
                    ProviderKey::Test->value => env(self::ENV_FLAG, false) && ! app()->environment('production')
                        ? TestProvider::class
                        : null,
                ],
            ]);

            $registry = app(ProviderRegistry::class);

            $this->assertFalse($registry->has(ProviderKey::Test), 'ProviderRegistry::has() must report the Test key as not resolvable in production even when the flag is true.');
            $this->assertSame([], $registry->all(), 'ProviderRegistry::all() must exclude the Test provider entirely in production even when the flag is true.');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    // ------------------------------------------------------------
    // 2. NEGATIVE CONTROL — flag=true, environment=non-production
    // (testing). Proves the fix did not accidentally break the
    // legitimate case: TestProvider must remain fully available
    // outside production when explicitly enabled.
    // ------------------------------------------------------------

    public function test_flag_true_in_a_non_production_environment_still_enables_is_enabled_by_environment(): void
    {
        $this->setEnvFlag('true');

        // No detectEnvironment() call — this suite already runs under
        // the real APP_ENV=testing (confirmed in setUp()), which is
        // the realistic non-production environment this test harness
        // can genuinely simulate, per this task's own guidance.
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->environment('production'));

        $provider = new TestProvider;

        $this->assertTrue(
            $this->isEnabledByEnvironment($provider),
            'isEnabledByEnvironment() must remain true when the flag is true and the environment is genuinely non-production (testing) — the fix must not narrow this legitimate case.'
        );
        $this->assertTrue($provider->isConfigured());
    }

    public function test_flag_true_in_a_non_production_environment_still_registers_test_provider_in_config_file(): void
    {
        $this->setEnvFlag('true');

        $config = $this->requireConfigFile();

        $this->assertSame(
            TestProvider::class,
            $config['providers'][ProviderKey::Test->value],
            'config/integrations.php must still map the Test provider key to TestProvider::class when the flag is true and the environment is non-production.'
        );
    }

    public function test_flag_true_in_a_non_production_environment_provider_registry_still_resolves_test_provider(): void
    {
        // Runtime-faithful companion to the production-throws proof
        // above, exercised through the identical narrowed expression
        // and the same real ProviderRegistry — proving the AND-guard
        // change is genuinely narrowing-only, not a regression that
        // also broke the legitimate non-production path.
        $this->setEnvFlag('true');

        config([
            'integrations.providers' => [
                ProviderKey::Test->value => env(self::ENV_FLAG, false) && ! app()->environment('production')
                    ? TestProvider::class
                    : null,
            ],
        ]);

        $registry = app(ProviderRegistry::class);

        $resolved = $registry->get(ProviderKey::Test);

        $this->assertInstanceOf(TestProvider::class, $resolved);
        $this->assertTrue($registry->has(ProviderKey::Test));
        $this->assertCount(1, $registry->all());
    }

    // ------------------------------------------------------------
    // 3. NEGATIVE CONTROL — flag=false, regardless of environment.
    // Proves the original gate is unchanged: TestProvider must remain
    // disabled when the flag itself is off, whether or not the
    // environment is production.
    // ------------------------------------------------------------

    public function test_flag_false_in_a_non_production_environment_remains_disabled(): void
    {
        $this->setEnvFlag('false');

        $this->assertFalse(app()->environment('production'));

        $provider = new TestProvider;

        $this->assertFalse($this->isEnabledByEnvironment($provider));
        $this->assertFalse($provider->isConfigured());

        $config = $this->requireConfigFile();
        $this->assertNull($config['providers'][ProviderKey::Test->value]);
    }

    public function test_flag_false_in_production_remains_disabled(): void
    {
        $this->setEnvFlag('false');

        try {
            app()->detectEnvironment(fn () => 'production');
            $this->assertTrue(app()->environment('production'));

            $provider = new TestProvider;

            $this->assertFalse(
                $this->isEnabledByEnvironment($provider),
                'flag=false in production must remain disabled — unchanged behavior from before this checkpoint, both conditions failing rather than just one.'
            );
            $this->assertFalse($provider->isConfigured());

            $config = $this->requireConfigFile();
            $this->assertNull($config['providers'][ProviderKey::Test->value]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_flag_unset_in_a_non_production_environment_remains_disabled(): void
    {
        // Default-OFF baseline (env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false))
        // must still hold when the flag is genuinely absent, not merely
        // explicitly false — unaffected by this checkpoint's change.
        $this->setEnvFlag(null);

        $this->assertFalse(app()->environment('production'));

        $provider = new TestProvider;

        $this->assertFalse($this->isEnabledByEnvironment($provider));
        $this->assertFalse($provider->isConfigured());
    }

    // ------------------------------------------------------------
    // Full truth table, single assertion set — a compact restatement
    // of every cell proving this is a pure AND-narrowing change: the
    // only cell whose behavior differs from "flag alone" is
    // (flag=true, environment=production).
    // ------------------------------------------------------------

    public function test_full_truth_table_only_the_flag_true_production_cell_is_disabled_by_the_environment_term(): void
    {
        $cases = [
            // [flagValue, environment, expectedEnabled]
            ['true', 'testing', true],
            ['true', 'production', false], // <- the only cell this checkpoint changes
            ['false', 'testing', false],
            ['false', 'production', false],
            [null, 'testing', false],
            [null, 'production', false],
        ];

        foreach ($cases as [$flagValue, $environment, $expectedEnabled]) {
            $this->setEnvFlag($flagValue);

            try {
                app()->detectEnvironment(fn () => $environment);

                $provider = new TestProvider;
                $actual = $this->isEnabledByEnvironment($provider);

                $this->assertSame(
                    $expectedEnabled,
                    $actual,
                    sprintf(
                        'Truth table mismatch for flag=%s, environment=%s: expected isEnabledByEnvironment()=%s, got %s.',
                        $flagValue ?? 'unset',
                        $environment,
                        $expectedEnabled ? 'true' : 'false',
                        $actual ? 'true' : 'false',
                    )
                );
            } finally {
                app()->detectEnvironment(fn () => 'testing');
            }
        }
    }
}
