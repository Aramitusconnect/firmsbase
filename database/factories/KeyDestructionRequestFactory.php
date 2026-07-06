<?php

namespace Database\Factories;

use App\Enums\KeyDestructionRequestStatus;
use App\Models\Firm;
use App\Models\KeyDestructionRequest;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeyDestructionRequest>
 */
class KeyDestructionRequestFactory extends Factory
{
    protected $model = KeyDestructionRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'offboarding_request_id' => null,
            'tenant_encryption_key_id' => null,
            'status' => KeyDestructionRequestStatus::Requested,
            'reason' => 'Firm offboarding complete; destroying envelope encryption key.',
            'requested_by_platform_admin_id' => PlatformAdmin::factory(),
            'requested_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
