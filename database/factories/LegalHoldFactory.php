<?php

namespace Database\Factories;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalHold>
 */
class LegalHoldFactory extends Factory
{
    protected $model = LegalHold::class;

    public function definition(): array
    {
        $admin = PlatformAdmin::factory()->create();

        return [
            'firm_id' => Firm::factory(),
            'scope_type' => LegalHoldScope::Firm,
            'client_id' => null,
            'matter_id' => null,
            'document_id' => null,
            'reason' => 'Pending litigation.',
            'status' => LegalHoldStatus::Active,
            'placed_by_type' => PlatformAdmin::class,
            'placed_by_id' => $admin->id,
            'placed_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => LegalHoldStatus::Released,
            'released_by_type' => PlatformAdmin::class,
            'released_by_id' => PlatformAdmin::factory(),
            'released_at' => now(),
            'release_reason' => 'Litigation concluded.',
        ]);
    }
}
