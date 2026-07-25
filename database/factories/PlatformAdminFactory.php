<?php

namespace Database\Factories;

use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PlatformAdmin>
 */
class PlatformAdminFactory extends Factory
{
    protected $model = PlatformAdmin::class;

    /**
     * MFA design proposal / EnsurePlatformAdminMfaIsEnrolledAndVerified
     * cascading update: MFA is now mandatory panel-wide, so a
     * never-enrolled admin is no longer a realistic "normal" fixture —
     * it is a specific, deliberately-unenrolled edge case (see
     * unenrolledInMfa() below). Defaulting to enrolled here is what let
     * the large majority of pre-existing tests that create a
     * PlatformAdmin and hit a real `/admin/...` route via actingAs()
     * keep working unchanged after this checkpoint, without needing a
     * per-test-file edit — only the small number of tests that
     * specifically assert on never-enrolled/MFA-setup behavior
     * (tests/Feature/Security/PlatformAdminMfa/*) opt out explicitly,
     * which they already did before this factory default changed
     * (every one of them sets two_factor_secret itself, so this default
     * is irrelevant to them either way).
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
        ];
    }

    /**
     * Explicit opt-out for tests that specifically need a never-enrolled
     * fixture (e.g. proving EnsurePlatformAdminMfaIsEnrolledAndVerified's
     * enrollment-check step, or Filament's own set-up-required flow).
     */
    public function unenrolledInMfa(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
