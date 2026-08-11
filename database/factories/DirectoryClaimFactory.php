<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryClaim>
 */
class DirectoryClaimFactory extends Factory
{
    protected $model = DirectoryClaim::class;

    public function definition(): array
    {
        return [
            'directory_firm_id' => DirectoryFirm::factory(),
            'firm_id' => Firm::factory(),
            'claimant_firm_user_id' => FirmUser::factory(),
            'state' => ClaimState::Pending,
            'claim_basis' => 'I am the owner of this firm.',
            'submitted_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function forDirectoryFirmAndClaimant(DirectoryFirm $directoryFirm, FirmUser $claimant): static
    {
        return $this->state(fn () => [
            'directory_firm_id' => $directoryFirm->id,
            'firm_id' => $claimant->firm_id,
            'claimant_firm_user_id' => $claimant->id,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn () => ['state' => ClaimState::UnderReview]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'state' => ClaimState::Approved,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'state' => ClaimState::Rejected,
            'decided_at' => now(),
            'rejection_reason' => 'Insufficient evidence of authority over this listing.',
        ]);
    }

    public function disputed(): static
    {
        return $this->state(fn () => ['state' => ClaimState::Disputed]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'state' => ClaimState::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }
}
