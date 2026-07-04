<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterAssignment>
 */
class MatterAssignmentFactory extends Factory
{
    protected $model = MatterAssignment::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'user_id' => User::factory(),
            'role' => 'attorney',
            'is_lead' => false,
            'assigned_at' => now(),
            'removed_at' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['matter_id' => $matter->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function lead(): static
    {
        return $this->state(fn () => ['is_lead' => true]);
    }
}
