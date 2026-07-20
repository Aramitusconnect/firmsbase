<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IntegrationProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * IntegrationProviderTest — Checkpoint 2 (Platform Provider Metadata),
 * checkpoint-00-final-specification.md §5 table #1.
 *
 * `integration_providers` is Global/platform-wide reference data: there
 * is no firm_id column and no tenant dimension at all, so RLS/FORCE RLS
 * is a deliberate exemption, not an oversight. Because there is no
 * firm_id to deny by, there is no "ordinary firm denial" test possible
 * or appropriate here (per the assignment brief) — the correct proof
 * for a Global table is that it is genuinely, verifiably open at the
 * database catalog level (RLS not enabled, not forced, zero policies),
 * not merely "nobody happened to add a policy yet."
 */
class IntegrationProviderTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_COLUMNS = [
        'id',
        'code',
        'display_name',
        'category',
        'auth_method',
        'status',
        'module_code',
        'degradation_type_key',
        'required_oauth_scopes_json',
        'webhook_event_types_json',
        'created_at',
        'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_integration_providers_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_providers'));
    }

    public function test_integration_providers_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_providers');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame(
            $expected,
            $columns,
            'integration_providers must have exactly the documented column set — no more, no fewer.'
        );
    }

    public function test_integration_providers_has_no_firm_id_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('integration_providers', 'firm_id'),
            'integration_providers is Global reference data with no tenant dimension — it must never gain a firm_id column.'
        );
    }

    // ------------------------------------------------------------
    // 2. RLS exemption is real and deliberate, not accidental
    // ------------------------------------------------------------

    public function test_integration_providers_does_not_have_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_providers'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relrowsecurity,
            'integration_providers has no firm_id/tenant dimension at all — RLS must not be enabled on it.'
        );
    }

    public function test_integration_providers_does_not_have_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_providers'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relforcerowsecurity,
            'integration_providers has no firm_id/tenant dimension at all — FORCE RLS must not be enabled on it.'
        );
    }

    public function test_integration_providers_has_zero_row_level_security_policies(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_providers'");

        $this->assertCount(
            0,
            $rows,
            'integration_providers must have zero pg_policies rows — a Global, un-tenanted table should never accumulate a stray policy.'
        );
    }

    // ------------------------------------------------------------
    // 3. Seed data correctness
    // ------------------------------------------------------------

    public function test_exactly_one_row_is_seeded_after_migration(): void
    {
        $this->assertSame(1, DB::table('integration_providers')->count());
    }

    public function test_seeded_row_matches_the_test_provider_key(): void
    {
        $row = DB::table('integration_providers')->first();

        $this->assertNotNull($row);
        $this->assertSame(ProviderKey::Test->value, $row->code);
    }

    public function test_no_row_exists_for_any_real_provider_code(): void
    {
        $realProviderCodes = [
            'google', 'microsoft', 'stripe', 'quickbooks', 'lawpay',
            'clio', 'plaid', 'zoom', 'dropbox', 'xero', 'docusign',
        ];

        $existing = DB::table('integration_providers')
            ->whereIn('code', $realProviderCodes)
            ->pluck('code')
            ->all();

        $this->assertSame(
            [],
            $existing,
            'No real provider (google/microsoft/stripe/etc.) is registered in this mission — seeding a catalog row for one would be out of scope.'
        );
    }

    // ------------------------------------------------------------
    // 3b. Migration reversibility — automated, repeatable proof
    // ------------------------------------------------------------

    /**
     * Durable, automated replacement for the checkpoint's manual
     * rollback/reapplication verification (which was previously only
     * performed once by hand via direct psql queries against pg_class
     * around `artisan migrate:rollback`/`migrate`, and never captured
     * as a repeatable test).
     *
     * This targets the Checkpoint 2 migration file explicitly via
     * `--path` (not a bare `--step=1`) so the test keeps proving the
     * right thing even if a later migration is added after this one —
     * `--step=1` would silently start rolling back whatever migration
     * happens to be most-recently-applied at that point, which is not
     * what this test is meant to prove.
     *
     * Safety note on running DDL mid-test under RefreshDatabase: this
     * class's tests each run inside a real outer PostgreSQL transaction
     * (RefreshDatabase migrates once for the whole run, then wraps each
     * test method in `$connection->beginTransaction()` / `rollBack()`).
     * PostgreSQL — unlike MySQL — supports fully transactional DDL, and
     * Laravel's own Migrator wraps each migration's up()/down() in
     * `$connection->transaction()` whenever
     * `$grammar->supportsSchemaTransactions()` is true (true for pgsql)
     * and the migration doesn't opt out; because the outer test
     * transaction is already open, that inner call becomes a SAVEPOINT,
     * not a second top-level transaction. So the DROP TABLE (rollback)
     * and CREATE TABLE + INSERT (reapply) performed by this test happen
     * entirely inside the test's own outer transaction and are fully
     * undone by RefreshDatabase's normal end-of-test `rollBack()` —
     * exactly like any other write a test makes. No other test in this
     * class (or process) observes the table missing, and the table is
     * guaranteed to exist again after this test regardless of how it
     * ends, because rollback of the *outer* transaction — not any
     * cleanup code in this method — is what restores it. This was
     * confirmed empirically against a disposable database (running
     * this test alongside the full class) before being finalized here.
     */
    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $migrationFile = 'database/migrations/2026_09_01_010001_create_integration_providers_table.php';
        $migrationName = '2026_09_01_010001_create_integration_providers_table';

        $this->assertFileExists(
            base_path($migrationFile),
            'This test targets the Checkpoint 2 migration by an explicit path — the file must exist at the expected location.'
        );

        // 1. Confirm current state: the table exists with exactly the
        // documented seed row, and the migration is recorded as run.
        $this->assertTrue(Schema::hasTable('integration_providers'));

        $before = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($before);
        $this->assertSame('test', $before->code);
        $this->assertSame('Internal Test Provider (non-production)', $before->display_name);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $migrationName)->first(),
            'The Checkpoint 2 migration must already be recorded as run before this test can prove rollback/reapply.'
        );

        // 2. Roll back exactly this migration — targeted unambiguously
        // by --path, not a bare --step=1.
        $rollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $migrationFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $rollbackExit, 'migrate:rollback failed: '.Artisan::output());

        // 3. The table must be gone — verified both via the schema
        // builder and directly against the PostgreSQL catalog.
        $this->assertFalse(
            Schema::hasTable('integration_providers'),
            'migrate:rollback targeted at the Checkpoint 2 migration must drop integration_providers.'
        );
        $this->assertNull(
            DB::selectOne("select relname from pg_class where relname = 'integration_providers'"),
            'integration_providers must be fully absent from the PostgreSQL catalog after rollback, not merely hidden from the schema builder.'
        );
        $this->assertNull(
            DB::table('migrations')->where('migration', $migrationName)->first(),
            'The rolled-back migration must no longer be recorded in the migrations table.'
        );

        // 4. Reapply exactly this migration.
        $migrateExit = Artisan::call('migrate', [
            '--path' => $migrationFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $migrateExit, 'migrate failed: '.Artisan::output());

        // 5. Assert the exact prior state is restored — same columns,
        // same single seed row with the same values — not merely "a
        // table now exists."
        $this->assertTrue(Schema::hasTable('integration_providers'));

        $columns = Schema::getColumnListing('integration_providers');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame(
            $expectedColumns,
            $columns,
            'Reapplying the migration must restore exactly the documented column set.'
        );

        $this->assertSame(1, DB::table('integration_providers')->count());

        $after = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($after);
        $this->assertSame($before->code, $after->code);
        $this->assertSame($before->display_name, $after->display_name);
        $this->assertSame($before->category, $after->category);
        $this->assertSame($before->auth_method, $after->auth_method);
        $this->assertSame($before->status, $after->status);
        $this->assertNull($after->module_code);
        $this->assertNull($after->degradation_type_key);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $migrationName)->first(),
            'The reapplied migration must be recorded as run again.'
        );

        // 6. No RLS ever gets silently (re)applied to this Global table
        // by the rollback/reapply cycle either.
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_providers'");
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity);
    }

    /**
     * Second, narrower proof of reversibility that calls the migration
     * file's own up()/down() methods directly, bypassing Artisan's
     * migrate/migrate:rollback commands and the `migrations` tracking
     * table entirely. This still stays safely inside RefreshDatabase's
     * outer per-test transaction — PostgreSQL supports transactional
     * DDL, so the DROP TABLE and CREATE TABLE/INSERT performed here are
     * just more writes inside that same outer transaction, undone by
     * its normal end-of-test rollback regardless of how this method
     * itself ends.
     */
    public function test_migration_down_and_up_restores_exact_prior_state(): void
    {
        $this->assertTrue(Schema::hasTable('integration_providers'));

        $before = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($before, 'Expected the seeded test-provider row to exist before rollback.');

        $migration = include database_path('migrations/2026_09_01_010001_create_integration_providers_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('integration_providers'), 'Table must be fully dropped after down().');

        $migration->up();

        $this->assertTrue(Schema::hasTable('integration_providers'), 'Table must be fully restored after up().');

        $after = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($after, 'Expected the seeded test-provider row to be restored after up().');
        $this->assertSame($before->code, $after->code);
        $this->assertSame($before->display_name, $after->display_name);
        $this->assertSame($before->category, $after->category);
        $this->assertSame($before->auth_method, $after->auth_method);
        $this->assertSame($before->status, $after->status);
    }

    // ------------------------------------------------------------
    // 4. Model behavior
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_integration_providers(): void
    {
        $model = new IntegrationProvider();

        $this->assertSame('integration_providers', $model->getTable());
    }

    public function test_model_fillable_contains_exactly_the_expected_fields(): void
    {
        $model = new IntegrationProvider();

        $expected = [
            'code',
            'display_name',
            'category',
            'auth_method',
            'status',
            'module_code',
            'degradation_type_key',
            'required_oauth_scopes_json',
            'webhook_event_types_json',
        ];

        $fillable = $model->getFillable();

        sort($fillable);
        sort($expected);

        $this->assertSame($expected, $fillable);
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('created_at', $fillable);
        $this->assertNotContains('updated_at', $fillable);
    }

    public function test_json_columns_cast_to_array(): void
    {
        // NOTE: uses IntegrationProviderFactory::new() directly rather than
        // IntegrationProvider::factory() purely as a stylistic choice for
        // this test — both work correctly; see
        // test_model_factory_static_accessor_resolves_correctly_after_new_factory_override()
        // below, which specifically exercises the model's own factory()
        // accessor.
        $model = IntegrationProviderFactory::new()->create([
            'required_oauth_scopes_json' => ['scope.a', 'scope.b'],
            'webhook_event_types_json' => ['event.a'],
        ]);

        $fresh = IntegrationProvider::query()->findOrFail($model->id);

        $this->assertIsArray($fresh->required_oauth_scopes_json);
        $this->assertSame(['scope.a', 'scope.b'], $fresh->required_oauth_scopes_json);
        $this->assertIsArray($fresh->webhook_event_types_json);
        $this->assertSame(['event.a'], $fresh->webhook_event_types_json);
    }

    public function test_model_does_not_use_the_tenant_scoping_trait(): void
    {
        $traits = class_uses_recursive(IntegrationProvider::class);

        $this->assertArrayNotHasKey(
            BelongsToTenant::class,
            $traits,
            'IntegrationProvider is Global reference data — it must never use BelongsToTenant.'
        );
    }

    /**
     * DEFECT FIX VERIFICATION:
     * IntegrationProvider previously had no newFactory() override, so
     * Laravel's default Factory::resolveFactoryName() resolver — which
     * only special-cases the "App\Models\" prefix — mis-resolved
     * IntegrationProvider::factory() (the model lives in
     * App\Integrations\Models) to the nonexistent class
     * Database\Factories\Integrations\Models\IntegrationProviderFactory,
     * causing a fatal error for every caller of the standard accessor.
     *
     * A narrow production fix has since added a
     * `protected static function newFactory(): IntegrationProviderFactory`
     * override to app/Integrations/Models/IntegrationProvider.php that
     * returns IntegrationProviderFactory::new() directly. This test
     * exercises the model's own static factory() method (not
     * IntegrationProviderFactory::new() directly, which was only ever
     * the workaround used elsewhere in this file before the fix) to
     * prove IntegrationProvider::factory() now resolves correctly and
     * produces a genuine, persistable model instance without throwing.
     */
    public function test_model_factory_static_accessor_resolves_correctly_after_new_factory_override(): void
    {
        $factory = IntegrationProvider::factory();

        $this->assertInstanceOf(IntegrationProviderFactory::class, $factory);

        $provider = IntegrationProvider::factory()->create();

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertTrue($provider->exists);
        $this->assertNotNull($provider->id);

        $fresh = IntegrationProvider::query()->findOrFail($provider->id);
        $this->assertSame($provider->code, $fresh->code);
    }

    public function test_model_has_no_global_scope_applied(): void
    {
        // A tenant-scoped model in this codebase applies its scoping via
        // BelongsToTenant, which registers a global scope. Confirming
        // zero global scopes here is a second, independent proof (beyond
        // the trait-usage check above) that no tenant-filtering behavior
        // has been silently attached some other way.
        $model = new IntegrationProvider();

        $this->assertSame([], $model->getGlobalScopes());
    }

    // ------------------------------------------------------------
    // 5. No accidental executable coupling with ProviderRegistry
    // ------------------------------------------------------------

    public function test_provider_registry_source_has_no_database_or_eloquent_coupling(): void
    {
        $reflection = new ReflectionClass(ProviderRegistry::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertNotFalse($source);

        $forbiddenPatterns = [
            'IntegrationProvider::',
            'IntegrationProvider(',
            '\\DB::',
            'DB::table',
            'DB::select',
            'DB::selectOne',
            '::query()',
            'Eloquent',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $source,
                "ProviderRegistry must never query the database (found reference to '{$pattern}') — it resolves providers strictly via the code-defined config('integrations.providers') map."
            );
        }

        $this->assertStringNotContainsString(
            'use App\\Integrations\\Models\\IntegrationProvider',
            $source,
            'ProviderRegistry must not import the IntegrationProvider Eloquent model at all — the two are structurally decoupled.'
        );
    }

    // ------------------------------------------------------------
    // 6. Factory hygiene
    // ------------------------------------------------------------

    public function test_factory_generates_synthetic_codes_that_cannot_collide_with_real_or_seeded_codes(): void
    {
        $reservedCodes = [
            ProviderKey::Test->value,
            'google', 'microsoft', 'stripe', 'quickbooks', 'lawpay',
            'clio', 'plaid', 'zoom', 'dropbox', 'xero', 'docusign',
        ];

        $providers = IntegrationProviderFactory::new()->count(10)->create();

        foreach ($providers as $provider) {
            $this->assertNotContains(
                $provider->code,
                $reservedCodes,
                "Factory-generated code '{$provider->code}' must never collide with a real or seeded provider key."
            );
            $this->assertStringStartsWith(
                'test-fixture-',
                $provider->code,
                'Factory-generated codes must be obviously synthetic, not merely accidentally-unique.'
            );
        }

        // Uniqueness: no two factory-generated rows collide with each other.
        $this->assertSame(10, $providers->pluck('code')->unique()->count());
    }

    public function test_factory_definition_contains_no_secret_or_credential_shaped_field(): void
    {
        $definition = (new IntegrationProviderFactory())->definition();

        $suspiciousKeys = array_filter(
            array_keys($definition),
            static fn (string $key): bool => (bool) preg_match('/secret|token|password|credential|api_key/i', $key)
        );

        $this->assertSame(
            [],
            array_values($suspiciousKeys),
            'integration_providers has no secret/credential-shaped column, so the factory must never introduce one.'
        );
    }
}
