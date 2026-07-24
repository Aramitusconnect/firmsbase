<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use Illuminate\Support\Carbon;

/**
 * FirmIntegrationCredentialSummary — Checkpoint 10 (frozen-design-post-
 * security-review.md §9; agent-10h-architecture-security-review.md §8).
 * The MANDATORY, structural DTO boundary for `IntegrationCredential` —
 * no Filament Field/Column/Entry/RelationManager anywhere under
 * `app/Filament/Firm/**` may ever bind directly to an
 * `IntegrationCredential` Eloquent instance. Built EXCLUSIVELY from
 * `IntegrationCredentialService::getMaskedMetadata()`'s plain array
 * (never `$credential->toArray()`, never a raw attribute read) — that
 * method performs no decrypt call and returns only already-non-secret
 * fields, so this DTO structurally cannot carry
 * `encrypted_payload_ciphertext` or `webhook_routing_token` (both
 * `$hidden` on the model AND absent from `getMaskedMetadata()`'s
 * return shape).
 */
final readonly class FirmIntegrationCredentialSummary
{
    public function __construct(
        public int $id,
        public int $firmIntegrationId,
        public CredentialType $credentialType,
        public IntegrationCredentialStatus $status,
        public ?Carbon $expiresAt,
        public ?array $maskedDisplayMetadata,
        public ?Carbon $createdAt,
        public ?Carbon $rotatedAt,
        public ?Carbon $revokedAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $maskedMetadata  the exact return
     *   shape of IntegrationCredentialService::getMaskedMetadata() —
     *   never any other array shape.
     */
    public static function fromMaskedMetadata(array $maskedMetadata): self
    {
        return new self(
            id: (int) $maskedMetadata['id'],
            firmIntegrationId: (int) $maskedMetadata['firm_integration_id'],
            credentialType: $maskedMetadata['credential_type'],
            status: $maskedMetadata['status'],
            expiresAt: $maskedMetadata['expires_at'],
            maskedDisplayMetadata: $maskedMetadata['masked_display_metadata'],
            createdAt: $maskedMetadata['created_at'],
            rotatedAt: $maskedMetadata['rotated_at'],
            revokedAt: $maskedMetadata['revoked_at'],
        );
    }
}
