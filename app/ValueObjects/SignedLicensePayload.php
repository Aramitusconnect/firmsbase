<?php

namespace App\ValueObjects;

use App\Enums\DeploymentMode;

/**
 * SignedLicensePayload — the exact, canonical set of fields a signed
 * offline license artifact commits to (project rule 13 / approved
 * license design). toCanonicalJson() produces a deterministic,
 * sorted-key JSON string so the same logical payload always signs and
 * verifies identically regardless of PHP array key order.
 */
final readonly class SignedLicensePayload
{
    /**
     * @param  array<string>  $allowedModules
     * @param  array<string>  $allowedSeatClasses
     * @param  array<string>  $allowedPracticeAreas
     */
    public function __construct(
        public string $licensedTo,
        public string $licenseKey,
        public \DateTimeInterface $expiresAt,
        public DeploymentMode $deploymentMode,
        public array $allowedModules,
        public int $allowedUsers,
        public array $allowedSeatClasses,
        public array $allowedPracticeAreas,
        public string $supportLevel,
        public string $renewalRules,
        public int $gracePeriodDays,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'licensed_to' => $this->licensedTo,
            'license_key' => $this->licenseKey,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'deployment_mode' => $this->deploymentMode->value,
            'allowed_modules' => array_values($this->allowedModules),
            'allowed_users' => $this->allowedUsers,
            'allowed_seat_classes' => array_values($this->allowedSeatClasses),
            'allowed_practice_areas' => array_values($this->allowedPracticeAreas),
            'support_level' => $this->supportLevel,
            'renewal_rules' => $this->renewalRules,
            'grace_period_days' => $this->gracePeriodDays,
        ];
    }

    public function toCanonicalJson(): string
    {
        $data = $this->toArray();
        ksort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            licensedTo: $data['licensed_to'],
            licenseKey: $data['license_key'],
            expiresAt: new \DateTimeImmutable($data['expires_at']),
            deploymentMode: DeploymentMode::from($data['deployment_mode']),
            allowedModules: $data['allowed_modules'],
            allowedUsers: $data['allowed_users'],
            allowedSeatClasses: $data['allowed_seat_classes'],
            allowedPracticeAreas: $data['allowed_practice_areas'],
            supportLevel: $data['support_level'],
            renewalRules: $data['renewal_rules'],
            gracePeriodDays: $data['grace_period_days'],
        );
    }
}
