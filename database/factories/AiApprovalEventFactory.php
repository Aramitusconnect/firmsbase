<?php

namespace Database\Factories;

use App\Enums\AiApprovalEventType;
use App\Models\AiApprovalEvent;
use App\Models\AiApprovalRequest;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiApprovalEvent>
 */
class AiApprovalEventFactory extends Factory
{
    protected $model = AiApprovalEvent::class;

    public function definition(): array
    {
        return [
            'ai_approval_request_id' => AiApprovalRequest::factory(),
            'firm_id' => Firm::factory(),
            'event_type' => AiApprovalEventType::Submitted,
            'actor_id' => User::factory(),
        ];
    }

    public function forRequest(AiApprovalRequest $request): static
    {
        return $this->state(fn () => [
            'ai_approval_request_id' => $request->id,
            'firm_id' => $request->firm_id,
        ]);
    }
}
