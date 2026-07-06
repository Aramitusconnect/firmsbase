<?php

namespace Database\Factories;

use App\Enums\HighRiskChangeRequestStatus;
use App\Models\DeletionApproval;
use App\Models\DeletionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeletionApproval>
 */
class DeletionApprovalFactory extends Factory
{
    protected $model = DeletionApproval::class;

    public function definition(): array
    {
        return [
            'deletion_request_id' => DeletionRequest::factory(),
            'high_risk_change_request_id' => null,
            'status' => HighRiskChangeRequestStatus::Pending,
            'created_at' => now(),
        ];
    }

    public function forRequest(DeletionRequest $request): static
    {
        return $this->state(fn () => ['deletion_request_id' => $request->id]);
    }
}
