<?php

namespace Database\Factories;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformRole>
 */
class PlatformRoleFactory extends Factory
{
    protected $model = PlatformRole::class;

    public function definition(): array
    {
        return [
            'platform_admin_id' => PlatformAdmin::factory(),
            'role_code' => $this->faker->randomElement(PlatformRoleCode::cases())->value,
            'granted_at' => now(),
        ];
    }

    public function role(PlatformRoleCode $role): static
    {
        return $this->state(fn () => ['role_code' => $role->value]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
