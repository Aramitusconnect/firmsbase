<?php

namespace Database\Factories;

use App\Enums\IntakeSubmissionStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\IntakeSubmission;
use App\Models\IntakeTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeSubmission>
 */
class IntakeSubmissionFactory extends Factory
{
    protected $model = IntakeSubmission::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => Client::factory(),
            'matter_id' => null,
            'intake_template_id' => IntakeTemplate::factory(),
            'status' => IntakeSubmissionStatus::Draft,
            'responses_json' => [],
            'submitted_at' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => ['firm_id' => $client->firm_id, 'client_id' => $client->id]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => IntakeSubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
