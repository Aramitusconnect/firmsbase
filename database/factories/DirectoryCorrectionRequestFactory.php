<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryCorrectionRequest>
 */
class DirectoryCorrectionRequestFactory extends Factory
{
    protected $model = DirectoryCorrectionRequest::class;

    public function definition(): array
    {
        return [
            'directory_firm_id' => DirectoryFirm::factory(),
            'correction_type' => CorrectionType::IncorrectPhone,
            'state' => CorrectionState::Pending,
            'description' => 'The phone number listed is no longer in service.',
            'reporter_name' => $this->faker->name(),
            'reporter_email' => $this->faker->safeEmail(),
        ];
    }

    public function forFirm(DirectoryFirm $firm): static
    {
        return $this->state(fn () => ['directory_firm_id' => $firm->id]);
    }

    public function underReview(): static
    {
        return $this->state(fn () => ['state' => CorrectionState::UnderReview]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['state' => CorrectionState::Approved, 'decided_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'state' => CorrectionState::Rejected,
            'decided_at' => now(),
            'rejection_reason' => 'Unable to independently confirm this report.',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'state' => CorrectionState::Resolved,
            'decided_at' => now(),
            'resolution_notes' => 'Listing updated.',
        ]);
    }
}
