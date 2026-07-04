<?php

namespace Database\Factories;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmUser>
 */
class FirmUserFactory extends Factory
{
    protected $model = FirmUser::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'firm_id' => Firm::factory(),
            'role' => FirmUserRole::Attorney,
            'status' => FirmUserStatus::Active,
            'is_primary' => false,
            'invited_by' => null,
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function role(FirmUserRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
