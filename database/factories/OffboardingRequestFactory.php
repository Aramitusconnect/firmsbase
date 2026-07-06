<?php

namespace Database\Factories;

use App\Enums\OffboardingRequestStatus;
use App\Models\Firm;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingRequest>
 */
class OffboardingRequestFactory extends Factory
{
    protected $model = OffboardingRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'status' => OffboardingRequestStatus::Requested,
            'reason' => 'Firm has cancelled and requested full offboarding.',
            'requested_by_platform_admin_id' => PlatformAdmin::factory(),
            'requested_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
