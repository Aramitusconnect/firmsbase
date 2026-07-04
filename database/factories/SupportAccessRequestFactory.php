<?php

namespace Database\Factories;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportAccessRequest>
 */
class SupportAccessRequestFactory extends Factory
{
    protected $model = SupportAccessRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'requested_by' => PlatformAdmin::factory(),
            'access_type' => SupportAccessType::Standard->value,
            'reason' => 'Investigating a client-reported billing discrepancy.',
            'status' => SupportAccessRequestStatus::Requested->value,
            'requested_duration_minutes' => 60,
        ];
    }

    public function emergency(): static
    {
        return $this->state(fn () => [
            'access_type' => SupportAccessType::Emergency->value,
            'emergency_justification' => 'Production incident requiring immediate platform staff access.',
        ]);
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
