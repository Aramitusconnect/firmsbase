<?php

namespace Database\Factories;

use App\Enums\ApiRequestStatus;
use App\Models\ApiRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequest>
 */
class ApiRequestFactory extends Factory
{
    protected $model = ApiRequest::class;

    public function definition(): array
    {
        return [
            'endpoint_identifier' => 'clients.index',
            'method' => 'GET',
            'status' => ApiRequestStatus::Success->value,
            'occurred_at' => now(),
        ];
    }
}
