<?php

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\PaymentMode;
use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmSettings>
 */
class FirmSettingsFactory extends Factory
{
    protected $model = FirmSettings::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'payment_mode' => PaymentMode::OperatingPaymentsOnly,
            'trust_iolta_protection' => true,
            'ai_mode' => AiMode::Disabled,
            'client_2fa_mode' => TwoFactorMode::Optional,
            'portal_frontend_mode' => null,
            'state_jurisdiction' => null,
            'default_language' => 'en',
            'branding_settings_json' => [],
            'security_settings_json' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
