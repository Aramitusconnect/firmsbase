<?php

namespace Database\Factories;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HighRiskChangeRequest>
 */
class HighRiskChangeRequestFactory extends Factory
{
    protected $model = HighRiskChangeRequest::class;

    public function definition(): array
    {
        return [
            'change_type' => HighRiskChangeType::TrustModeActivation->value,
            'status' => HighRiskChangeRequestStatus::Pending->value,
            'reason' => 'Firm has completed trust accounting onboarding review.',
            'requested_by' => PlatformAdmin::factory(),
        ];
    }

    public function changeType(HighRiskChangeType $type): static
    {
        return $this->state(fn () => ['change_type' => $type->value]);
    }
}
