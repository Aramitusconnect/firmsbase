<?php

namespace Tests\Feature\Security\SeedData;

use App\Services\SeedDataSecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DatabaseSeederSafetyTest — Section 39E. Proves DatabaseSeeder no
 * longer creates a login-capable default user (test@example.com /
 * factory default "password") outside local/testing, while still
 * working correctly for local/testing use (this suite itself runs
 * under APP_ENV=testing per phpunit.xml).
 */
class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_file_exists_and_is_guarded_to_local_or_testing(): void
    {
        $path = database_path('seeders/DatabaseSeeder.php');

        $this->assertFileExists($path);

        $source = file_get_contents($path);

        $this->assertStringContainsString("environment(['local', 'testing'])", $source);
    }

    public function test_no_production_executable_seeder_creates_a_login_capable_default_user(): void
    {
        $service = new SeedDataSecurityAuditService();

        $this->assertEmpty($service->productionSeedRisk(), 'No seeder may create a login-capable default user without a local/testing guard.');
    }

    public function test_no_production_executable_seeder_uses_the_default_password_string(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        // The seeder relies on UserFactory's shared test-only default
        // password, but only reaches that code path inside the
        // local/testing guard — confirmed by the guard assertion above
        // and by the fact that this literal password string appears
        // nowhere in the seeder's own source.
        $this->assertStringNotContainsString("'password'", $source);
        $this->assertStringNotContainsString('Hash::make', $source);
    }

    public function test_database_seeder_still_works_correctly_inside_the_testing_environment(): void
    {
        $this->assertTrue(app()->environment('testing'));

        (new \Database\Seeders\DatabaseSeeder())->run();

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_database_seeder_does_not_run_outside_local_or_testing(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->assertFalse(app()->environment(['local', 'testing']));

            (new \Database\Seeders\DatabaseSeeder())->run();

            $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
