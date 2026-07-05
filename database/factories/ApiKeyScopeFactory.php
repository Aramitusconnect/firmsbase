<?php

namespace Database\Factories;

use App\Enums\ApiKeyScopeCode;
use App\Models\ApiKey;
use App\Models\ApiKeyScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKeyScope>
 */
class ApiKeyScopeFactory extends Factory
{
    protected $model = ApiKeyScope::class;

    public function definition(): array
    {
        return [
            'api_key_id' => ApiKey::factory(),
            'scope_code' => ApiKeyScopeCode::ClientsRead->value,
            'granted_at' => now(),
        ];
    }

    public function scope(ApiKeyScopeCode $scope): static
    {
        return $this->state(fn () => ['scope_code' => $scope->value]);
    }
}
