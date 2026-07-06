<?php

namespace Database\Factories;

use App\Enums\DeletionRequestStatus;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\DeletionRequest>
 *
 * Defaults to targeting a Matter as the subject, since deletion
 * governance may target many record types over time (approved
 * decision #9).
 */
class DeletionRequestFactory extends Factory
{
    protected $model = \App\Models\DeletionRequest::class;

    public function definition(): array
    {
        $matter = Matter::factory()->create();

        return [
            'firm_id' => $matter->firm_id,
            'subject_type' => Matter::class,
            'subject_id' => $matter->id,
            'subject_snapshot_json' => ['matter_id' => $matter->id],
            'reason' => 'Platform admin requested governed hard delete after retention expiry.',
            'status' => DeletionRequestStatus::Requested,
            'requested_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
