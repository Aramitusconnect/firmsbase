<?php

namespace Database\Factories;

use App\Models\AiPolicySetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiPolicySetting>
 */
class AiPolicySettingFactory extends Factory
{
    protected $model = AiPolicySetting::class;

    public function definition(): array
    {
        return [
            'key' => 'test_policy_'.Str::random(8),
            'value_json' => ['enabled' => true],
        ];
    }
}
