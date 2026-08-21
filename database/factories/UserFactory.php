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
            // Mirrors PlatformAdminFactory's own default exactly:
            // FirmUser2faPolicyService's platform-minimum MFA floor
            // (Non-Payment Completion Program, Workstream 7) now
            // requires 2FA confirmation for every FirmOwner/Attorney
            // regardless of a firm's own firm_user_2fa_mode setting,
            // and EnsureFirmUserMfaComplianceOrRedirectToEnrollment
            // redirects any non-compliant actor away from every Firm
            // panel route. A factory-created User with no explicit
            // override is therefore already 2FA-compliant by default
            // — the same "tests get a working actor unless they
            // deliberately ask for the non-compliant path" convention
            // PlatformAdminFactory already established. The 2FA-specific
            // test suites (tests/Feature/Security/FirmUser2fa/*) always
            // set this field explicitly for both the compliant and
            // non-compliant cases, so this default never masks what
            // those tests are actually proving.
            'two_factor_confirmed_at' => now(),
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

    /**
     * Indicate that the model has never confirmed 2FA — the explicit
     * opt-out from this factory's own compliant-by-default state, for
     * tests that specifically need to exercise the non-compliant path
     * (e.g. EnsureFirmUserMfaComplianceOrRedirectToEnrollment's own
     * redirect behavior).
     */
    public function withoutTwoFactorConfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_confirmed_at' => null,
        ]);
    }
}
