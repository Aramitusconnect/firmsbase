<?php

namespace Database\Factories;

use App\Enums\HighRiskChangeRequestStatus;
use App\Models\KeyDestructionApproval;
use App\Models\KeyDestructionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeyDestructionApproval>
 */
class KeyDestructionApprovalFactory extends Factory
{
    protected $model = KeyDestructionApproval::class;

    public function definition(): array
    {
        return [
            'key_destruction_request_id' => KeyDestructionRequest::factory(),
            'high_risk_change_request_id' => null,
            'status' => HighRiskChangeRequestStatus::Pending,
            'created_at' => now(),
        ];
    }

    public function forRequest(KeyDestructionRequest $request): static
    {
        return $this->state(fn () => ['key_destruction_request_id' => $request->id]);
    }
}
