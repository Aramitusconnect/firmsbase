<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderCommandType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderCommand — FirmsVault Pay Gate A2. The durable, tenant-owned
 * ECONOMIC INSTRUCTION (v1.4 §12), created inside the financial domain
 * transaction and committed atomically with it and its outbox row.
 *
 * It is NOT the at-most-once send gate. That remains
 * App\Integrations\Billing\ProviderOperationAttemptService /
 * `provider_operation_attempts`, reused unchanged on its independent
 * durable connection. This row supplies that gate its key via
 * logicalOperationKey() — see this class's table migration docblock for
 * the full reasoning on why the two must be separate objects.
 *
 * IMMUTABLE ENVELOPE. self::ENVELOPE_FIELDS can never change after
 * creation; the guard below enforces it. Only execution metadata
 * (status, timestamps, provider_reference, last_error,
 * reconciliation_required) is mutable.
 */
class ProviderCommand extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    /**
     * The immutable economic envelope (v1.4 §12). Everything a provider
     * would need to know WHAT to do; nothing about how it went.
     */
    public const ENVELOPE_FIELDS = [
        'uuid',
        'firm_id',
        'firm_integration_id',
        'integration_provider_id',
        'command_type',
        'aggregate_type',
        'aggregate_id',
        'idempotency_key',
        'canonical_payload_hash',
        'canonical_payload',
        'correlation_id',
        'payment_intent_id',
    ];

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'integration_provider_id',
        'command_type',
        'aggregate_type',
        'aggregate_id',
        'idempotency_key',
        'canonical_payload_hash',
        'canonical_payload',
        'correlation_id',
        'payment_intent_id',
        'status',
        'enqueued_at',
        'submitted_at',
        'resolved_at',
        'provider_reference',
        'last_error',
        'reconciliation_required',
    ];

    protected function casts(): array
    {
        return [
            'command_type' => ProviderCommandType::class,
            'status' => ProviderCommandStatus::class,
            'canonical_payload' => 'array',
            'aggregate_id' => 'integer',
            'reconciliation_required' => 'boolean',
            'enqueued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $command) {
            $changedEnvelope = array_intersect(
                array_keys($command->getDirty()),
                self::ENVELOPE_FIELDS,
            );

            if ($changedEnvelope !== []) {
                throw new \LogicException(
                    'provider_commands: the economic envelope is immutable — refusing to change ['
                    .implode(', ', $changedEnvelope).']. Issue a new command instead.'
                );
            }
        });

        static::deleting(function () {
            throw new \LogicException(
                'provider_commands: an economic instruction can never be deleted; it is the audit record '
                .'of what this system asked a payment provider to do.'
            );
        });
    }

    /**
     * The key handed to the EXISTING durable at-most-once gate
     * (ProviderOperationAttemptService). Derived deterministically from
     * this row's immutable uuid, so:
     *
     *   - the same command always maps to the same gate row, no matter
     *     which worker computes it or how many times;
     *   - a second worker can never obtain a second send permission for
     *     the same economic instruction;
     *   - the key contains no wall-clock time and no mutable state,
     *     matching that gate's own "deterministic hash of stable
     *     business inputs, never wall-clock time" requirement.
     *
     * The `fvpay:` prefix namespaces FirmsVault Pay's keys away from the
     * integration-sync keys already in that table.
     */
    public function logicalOperationKey(): string
    {
        return 'fvpay:'.$this->uuid;
    }

    /**
     * The canonical payload hash for a given payload array. The single
     * definition used by both writing and conflict detection, so the two
     * can never disagree.
     *
     * Canonicalization: recursive key sort, then JSON. Two payloads that
     * differ only in key order are the SAME economic instruction; two
     * that differ in any value are not.
     */
    public static function canonicalPayloadHash(array $payload): string
    {
        return hash('sha256', self::canonicalJson($payload));
    }

    public static function canonicalJson(array $payload): string
    {
        $canonical = self::deepKeySort($payload);

        return json_encode($canonical, JSON_THROW_ON_ERROR);
    }

    private static function deepKeySort(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::deepKeySort($item);
            }
        }

        return $value;
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

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}
