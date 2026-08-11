<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<DirectoryVerification>
 */
class DirectoryVerificationFactory extends Factory
{
    protected $model = DirectoryVerification::class;

    public function definition(): array
    {
        return [
            'verifiable_type' => DirectoryFirm::class,
            'verifiable_id' => DirectoryFirm::factory(),
            'dimension' => VerificationDimension::FirmAuthority,
            'state' => VerificationState::Pending,
            'source' => null,
        ];
    }

    public function forVerifiable(Model $verifiable, VerificationDimension $dimension): static
    {
        return $this->state(fn () => [
            'verifiable_type' => $verifiable::class,
            'verifiable_id' => $verifiable->id,
            'dimension' => $dimension,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'state' => VerificationState::Verified,
            'source' => VerificationSource::AdminDocumentReview,
            'verified_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'state' => VerificationState::Revoked,
            'revoked_at' => now(),
            'revocation_reason' => 'Evidence could not be re-confirmed.',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'state' => VerificationState::Expired,
            'source' => VerificationSource::AdminDocumentReview,
            'verified_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);
    }
}
