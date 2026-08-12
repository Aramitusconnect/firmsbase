<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\FirmAiSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmAiSettings>
 */
class FirmAiSettingsFactory extends Factory
{
    protected $model = FirmAiSettings::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'allowed_providers_json' => ['openai'],
            'allowed_models_json' => ['fake-model-1'],
            'token_limit_per_period' => 100000,
            'budget_limit_cents_per_period' => 10000,
            'usage_markup_basis_points' => 0,
            'document_context_enabled' => false,
            'client_data_context_enabled' => false,
            'high_risk_requires_approval' => true,
            'full_content_logging_enabled' => false,
            'intake_ai_assist_enabled' => false,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
