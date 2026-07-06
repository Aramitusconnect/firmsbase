<?php

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageEvent>
 */
class AiUsageEventFactory extends Factory
{
    protected $model = AiUsageEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'user_id' => User::factory(),
            'matter_id' => null,
            'ai_mode' => AiMode::PlatformManaged,
            'provider' => AiProvider::OpenAi,
            'model' => 'fake-model-1',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_cents' => 1,
            'approval_required' => false,
            'action_type' => AiUsageActionType::Summarization,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function highRisk(AiUsageActionType $type = AiUsageActionType::LegalResearchMemo): static
    {
        return $this->state(fn () => [
            'action_type' => $type,
            'approval_required' => true,
        ]);
    }
}
