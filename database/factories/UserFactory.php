<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Deliberately NOT fake()->unique()->safeEmail(): Faker's
            // unique() modifier only tracks uniqueness within a single
            // Faker\Generator instance, and Laravel's test lifecycle
            // rebuilds the whole container (and therefore the Faker
            // singleton) on every test method. Across the full suite's
            // thousands of User::factory() calls, safeEmail()'s bounded
            // name/domain pool made a real cross-test-method collision
            // on users_email_unique a reproducible, non-deterministic
            // full-suite failure (confirmed independently 4 times
            // during the Phase 3 final test-gate verification, always
            // a different colliding email in a different, unrelated,
            // pre-existing test file each time). Str::random(24) has a
            // collision probability low enough to never matter at any
            // realistic suite size, and no test anywhere asserts on the
            // specific shape/content of a factory-generated email
            // (verified: only equality/passthrough checks exist).
            'email' => Str::lower(Str::random(24)).'@'.fake()->safeEmailDomain(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
