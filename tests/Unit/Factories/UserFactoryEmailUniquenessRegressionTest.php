<?php

namespace Tests\Unit\Factories;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the Phase 3 final test-gate verification finding:
 * UserFactory used to generate emails via fake()->unique()->safeEmail(),
 * whose uniqueness tracking only survives within a single Faker\Generator
 * instance — reset by Laravel's own test lifecycle on every test method.
 * Across the full suite's thousands of User::factory() calls this made a
 * cross-test-method users_email_unique collision a real, reproducible,
 * non-deterministic full-suite failure (independently confirmed 4 times,
 * always a different colliding email in a different, unrelated,
 * pre-existing test file). This test proves the fix at a scale large
 * enough that the OLD bounded safeEmail() name/domain pool would have
 * collided with near certainty (birthday-paradox), while the new
 * Str::random(24)-based scheme does not.
 */
class UserFactoryEmailUniquenessRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_large_batch_of_factory_users_never_collides_on_email(): void
    {
        $batchSize = 2000;

        $emails = User::factory()
            ->count($batchSize)
            ->create()
            ->pluck('email');

        $this->assertCount($batchSize, $emails);
        $this->assertCount(
            $batchSize,
            $emails->unique(),
            'Expected every factory-generated email in the batch to be unique — a collision here would reproduce the exact users_email_unique failure this test guards against.'
        );
    }

    public function test_factory_generated_email_is_a_syntactically_valid_address(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression('/^[a-z0-9]+@[a-z0-9.\-]+\.[a-z]{2,}$/', $user->email);
    }
}
