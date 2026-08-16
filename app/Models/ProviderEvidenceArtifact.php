<?php

declare(strict_types=1);

namespace App\Models;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderEvidenceArtifact — FirmsVault Pay Gate A2 (v1.4 §42). An
 * evidence INDEX row: a storage reference plus a content hash, never
 * the provider bytes themselves.
 *
 * An ordinary tenant-owned model: firm_id is NOT NULL and every
 * artifact here is already attributed to a firm. Unresolved provider
 * ingress lives in the Global/EXEMPT `integration_webhook_receipts`
 * instead — see this model's create migration for why a nullable-firm
 * design was both unimplementable under FORCE RLS and unnecessary.
 *
 * Append-only: evidence is never rewritten. Redaction is expressed by
 * setting redacted_at and clearing the storage reference through the
 * dedicated service path, not by mutating the hash.
 */
class ProviderEvidenceArtifact extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'integration_provider_id',
        'provider_command_id',
        'evidence_type',
        'storage_disk',
        'storage_reference',
        'content_sha256',
        'content_bytes',
        'provider_context',
        'schema_version',
        'captured_at',
        'retention_deadline',
        'redacted_at',
    ];

    protected function casts(): array
    {
        return [
            'content_bytes' => 'integer',
            'schema_version' => 'integer',
            'captured_at' => 'datetime',
            'retention_deadline' => 'datetime',
            'redacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $artifact) {
            $immutable = array_intersect(
                array_keys($artifact->getDirty()),
                ['firm_id', 'provider_command_id', 'evidence_type', 'content_sha256', 'content_bytes'],
            );

            if ($immutable !== []) {
                throw new \LogicException(
                    'provider_evidence_artifacts: refusing to change immutable evidence field(s) ['
                    .implode(', ', $immutable).']. Evidence is tamper-evident by construction.'
                );
            }
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function integrationProvider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class);
    }

    public function providerCommand(): BelongsTo
    {
        return $this->belongsTo(ProviderCommand::class);
    }
}
