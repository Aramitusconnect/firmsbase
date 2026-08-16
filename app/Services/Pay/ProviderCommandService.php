<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\IdempotencyConflictException;
use App\Models\ProviderCommand;
use App\Services\TenantContextService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ProviderCommandService — FirmsVault Pay Gate A2 (v1.4 §12/§13). The
 * only writer of provider_commands.
 *
 * ============================================================
 * RELATIONSHIP TO THE EXISTING AT-MOST-ONCE GATE
 * ============================================================
 * This service does NOT replace
 * App\Integrations\Billing\ProviderOperationAttemptService. That engine
 * is reused unchanged, at the layer it was built for. The division is:
 *
 *   THIS SERVICE (transactional, tenant-owned)
 *     "what are we instructing the provider to do, and is this
 *      instruction identical to one we already recorded?"
 *     Runs inside the financial domain transaction. Commits or rolls
 *     back with it, so a rolled-back financial transaction can never
 *     leave an orphan economic instruction behind.
 *
 *   ProviderOperationAttemptService (independent connection, autocommit)
 *     "may this worker put a request on the wire, right now, exactly
 *      once, ever?"
 *     Runs at send time, keyed by ProviderCommand::logicalOperationKey().
 *     Its evidence deliberately survives caller rollback.
 *
 * Both are needed. Neither can do the other's job — see the
 * provider_commands migration docblock for the full analysis of why
 * "survives rollback" and "commits atomically" cannot be one row.
 * ============================================================
 *
 * IDEMPOTENCY (§13) is enforced by the DATABASE unique index on
 * (firm_id, idempotency_key). createOrReuse() attempts the insert and
 * lets the index arbitrate; it never pre-checks with a SELECT and then
 * trusts the gap, which is exactly the race a unique index exists to
 * close. On violation it re-reads the winner and compares canonical
 * payload hashes:
 *
 *   identical hash  -> the SAME logical command; return it (safe retry)
 *   different hash  -> IdempotencyConflictException, NO execution, audited
 */
class ProviderCommandService
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Create the economic instruction, or return the existing one if
     * this exact instruction was already recorded under this key.
     *
     * MUST be called from inside the caller's financial domain
     * transaction (v1.4 §14) so the command and the domain mutation
     * commit together. This method deliberately opens no transaction of
     * its own — doing so would create a nested boundary that could
     * commit independently of the caller.
     *
     * @param  array<string, mixed>  $canonicalPayload
     */
    public function createOrReuse(
        int $firmId,
        ProviderCommandType $commandType,
        string $aggregateType,
        int $aggregateId,
        string $idempotencyKey,
        array $canonicalPayload,
        ?int $firmIntegrationId = null,
        ?int $integrationProviderId = null,
        ?int $paymentIntentId = null,
        ?string $correlationId = null,
    ): ProviderCommand {
        $payloadHash = ProviderCommand::canonicalPayloadHash($canonicalPayload);

        try {
            // SAVEPOINT ISOLATION — not optional. In PostgreSQL, ANY
            // failed statement aborts the whole transaction: every
            // subsequent command returns 25P02 "current transaction is
            // aborted" until rollback. Because this method is required
            // to run inside the caller's financial domain transaction
            // (v1.4 §14), catching the unique violation without a
            // savepoint would leave that transaction poisoned and make
            // the idempotent-reuse path below impossible to execute.
            //
            // A nested DB::transaction() issues SAVEPOINT / ROLLBACK TO
            // SAVEPOINT, so a rejected insert unwinds only itself and
            // the caller's transaction remains usable.
            $command = DB::transaction(fn (): ProviderCommand => ProviderCommand::query()->create([
                'firm_id' => $firmId,
                'firm_integration_id' => $firmIntegrationId,
                'integration_provider_id' => $integrationProviderId,
                'command_type' => $commandType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'idempotency_key' => $idempotencyKey,
                'canonical_payload_hash' => $payloadHash,
                'canonical_payload' => $canonicalPayload,
                'correlation_id' => $correlationId ?? (string) Str::uuid(),
                'payment_intent_id' => $paymentIntentId,
                'status' => ProviderCommandStatus::Pending,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->resolveExisting($firmId, $idempotencyKey, $payloadHash);
        }

        $this->audit->record(PayAuditRecorder::COMMAND_CREATED, $firmId, [
            'provider_command_id' => $command->id,
            'command_type' => $commandType->value,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload_hash_prefix' => substr($payloadHash, 0, 12),
        ]);

        return $command;
    }

    /**
     * The idempotency arbitration path. Reached only when the unique
     * index rejected an insert, so a row provably exists.
     */
    private function resolveExisting(int $firmId, string $idempotencyKey, string $incomingHash): ProviderCommand
    {
        /** @var ProviderCommand|null $existing */
        $existing = ProviderCommand::query()
            ->where('firm_id', $firmId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            // The unique index fired but the row is not visible to us.
            // Fail closed rather than retrying into a possible second
            // insert: the caller may safely retry the whole operation.
            throw new \RuntimeException(
                'Idempotency key ['.$idempotencyKey.'] is taken but the owning provider command is not '
                .'readable in this tenant context. Refusing to proceed.'
            );
        }

        if (! hash_equals($existing->canonical_payload_hash, $incomingHash)) {
            $this->audit->record(PayAuditRecorder::IDEMPOTENCY_CONFLICT, $firmId, [
                'provider_command_id' => $existing->id,
                'existing_hash_prefix' => substr($existing->canonical_payload_hash, 0, 12),
                'incoming_hash_prefix' => substr($incomingHash, 0, 12),
            ]);

            throw new IdempotencyConflictException(
                $idempotencyKey,
                $existing->canonical_payload_hash,
                $incomingHash,
            );
        }

        $this->audit->record(PayAuditRecorder::COMMAND_IDEMPOTENT_REUSE, $firmId, [
            'provider_command_id' => $existing->id,
            'payload_hash_prefix' => substr($incomingHash, 0, 12),
        ]);

        return $existing;
    }

    /**
     * Move execution metadata forward. The immutable envelope is
     * untouched (and the model would refuse anyway).
     */
    public function transition(
        ProviderCommand $command,
        ProviderCommandStatus $next,
        ?string $providerReference = null,
        ?string $lastError = null,
    ): ProviderCommand {
        if (! $command->status->canTransitionTo($next)) {
            throw new \LogicException(
                'Illegal provider command transition ['.$command->status->value.' -> '.$next->value
                .'] for command ['.$command->id.'].'
            );
        }

        return $this->tenantContext->runWithFirmContext(
            (int) $command->firm_id,
            function () use ($command, $next, $providerReference, $lastError): ProviderCommand {
                $command->status = $next;

                if ($providerReference !== null) {
                    $command->provider_reference = $providerReference;
                }

                if ($lastError !== null) {
                    $command->last_error = $lastError;
                }

                if ($next === ProviderCommandStatus::Dispatched) {
                    $command->submitted_at = now();
                }

                if ($next->isTerminal()) {
                    $command->resolved_at = now();
                }

                // An undetermined outcome ALWAYS requires reconciliation
                // (v1.4 §23); the database CHECK enforces the same rule
                // independently of this line.
                if ($next === ProviderCommandStatus::OutcomeUnknown) {
                    $command->reconciliation_required = true;

                    $this->audit->record(PayAuditRecorder::OUTCOME_UNKNOWN, (int) $command->firm_id, [
                        'provider_command_id' => $command->id,
                        'aggregate_type' => $command->aggregate_type,
                        'aggregate_id' => $command->aggregate_id,
                    ]);
                }

                $command->save();

                return $command->refresh();
            },
        );
    }
}
