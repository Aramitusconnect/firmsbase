<?php

namespace Database\Factories;

use App\Enums\SalesAssignmentStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformLead;
use App\Models\SalesRepAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesRepAssignment>
 */
class SalesRepAssignmentFactory extends Factory
{
    protected $model = SalesRepAssignment::class;

    public function definition(): array
    {
        return [
            'assignable_type' => PlatformLead::class,
            'assignable_id' => PlatformLead::factory(),
            'platform_admin_id' => PlatformAdmin::factory(),
            'status' => SalesAssignmentStatus::Active->value,
            'assigned_at' => now(),
        ];
    }

    public function forAssignable(\Illuminate\Database\Eloquent\Model $assignable): static
    {
        return $this->state(fn () => [
            'assignable_type' => $assignable::class,
            'assignable_id' => $assignable->id,
        ]);
    }
}
