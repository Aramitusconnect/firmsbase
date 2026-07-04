<?php

namespace Database\Factories;

use App\Enums\DocumentRequestStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequest>
 */
class DocumentRequestFactory extends Factory
{
    protected $model = DocumentRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'client_id' => Client::factory(),
            'status' => DocumentRequestStatus::Open,
            'title' => 'Please provide the following documents',
            'instructions' => $this->faker->sentence(),
            'due_at' => now()->addDays(14),
            'created_by' => null,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => ['firm_id' => $client->firm_id, 'client_id' => $client->id]);
    }
}
