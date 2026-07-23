<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ExternalMappingConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IntegrationExternalMappingService — the ONLY writer of
 * `integration_external_mappings` (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §3/§6/§8;
 * agent-6c-idempotency-concurrency.md §8; agent-6f-mapping-conflict-design.md
 * §2). This table's rows are NEVER hard-deleted by application code —
 * this class exposes tombstone(), never delete()/forceDelete().
 *
 * recordMapping() deliberately does NOT use Eloquent's built-in
 * firstOrCreate() directly: that helper's internal `first()` lookup is
 * a plain ->where($attributes)->first() with no tombstoned_at
 * filtering, which would incorrectly match an OLD tombstoned row
 * instead of creating a fresh live one for the same
 * (firm_integration_id, resource_type, external_id) tuple — exactly
 * the re-mapping escape valve the partial unique indexes'
 * `WHERE tombstoned_at IS NULL` predicate exists to allow. This method
 * reimplements the identical createOrFirst()-shaped
 * try-create/catch-UniqueConstraintViolationException/re-SELECT
 * pattern with that filter applied explicitly (agent-6c §1's own
 * warning: "firstOrCreate() is only ever as safe as the migration
 * behind it").
 *
 * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) — the create()
 * attempt below is wrapped in its own DB::transaction() so PostgreSQL
 * issues a SAVEPOINT: recordMapping() is always called from inside
 * TenantContextService::runWithFirmContext()'s own outer
 * DB::transaction(), and PostgreSQL aborts the ENTIRE current
 * transaction block on any error until it is rolled back — without a
 * nested transaction here, a caught UniqueConstraintViolationException
 * would poison the outer transaction, and the catch block's own
 * findLiveByExternalId() re-SELECT would then itself fail against the
 * already-aborted transaction instead of finding the winning row. The
 * nested DB::transaction() call scopes PostgreSQL's abort to the
 * SAVEPOINT only, so the re-SELECT below runs against a still-healthy
 * outer transaction.
 */
final class IntegrationExternalMappingService
{
    public function recordMapping(
        FirmIntegration $connection,
        string $resourceType,
        string $localType,
        int $localId,
        string $externalId,
        SyncDirection $direction,
        ?string $externalVersionToken = null,
        ?string $localVersionToken = null,
    ): IntegrationExternalMapping {
        $existing = $this->findLiveByExternalId($connection->id, $resourceType, $externalId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            // Nested DB::transaction() -> PostgreSQL SAVEPOINT (see class
            // docblock's "POST-DIFF-REVIEW FIX" note): confines a caught
            // UniqueConstraintViolationException's transaction-abort to
            // this savepoint only, so the catch block's re-SELECT below
            // still runs against a healthy outer transaction.
            return DB::transaction(function () use (
                $connection,
                $resourceType,
                $localType,
                $localId,
                $externalId,
                $direction,
                $externalVersionToken,
                $localVersionToken,
            ) {
                return IntegrationExternalMapping::query()->create([
                    'firm_id' => $connection->firm_id,
                    'firm_integration_id' => $connection->id,
                    'resource_type' => $resourceType,
                    'local_type' => $localType,
                    'local_id' => $localId,
                    'external_id' => $externalId,
                    'sync_direction' => $direction,
                    'external_version_token' => $externalVersionToken,
                    'local_version_token' => $localVersionToken,
                    'last_synced_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The FIRST constraint (external_unique) firing here would mean a
            // genuinely concurrent insert of the exact same external object won
            // the race — re-select and return it, mirroring createOrFirst()'s
            // own internal recovery.
            $winner = $this->findLiveByExternalId($connection->id, $resourceType, $externalId);

            if ($winner !== null) {
                return $winner;
            }

            // Otherwise this is the SECOND constraint (local_unique) firing: this
            // exact local record is already mapped to a DIFFERENT external object
            // for this connection — a genuine data-integrity conflict, never
            // silently swallowed.
            throw new ExternalMappingConflictException($localType, $localId, $externalId);
        }
    }

    /**
     * CHECKPOINT 8 addition (agent-8h-architecture-security-review.md §1
     * item 3 / §2 item 4): refreshes ONLY the two version-token columns
     * on an already-resolved, still-live mapping row — e.g. a push job
     * that successfully re-pushes an already-mapped object and needs to
     * record the provider's fresh external_version_token. A single
     * guarded UPDATE, `WHERE id = ? AND tombstoned_at IS NULL`, matching
     * recordMapping()'s own "live-row-only" discipline — this method
     * creates no new row, changes no
     * (firm_integration_id, resource_type, local_type, local_id)
     * identity tuple, and cannot resurrect a tombstoned mapping (the
     * guard prevents that structurally). Throws if the guard matches
     * zero rows (the mapping is gone or has since been tombstoned) —
     * the caller must not assume success against a row that may have
     * been concurrently tombstoned.
     */
    public function refreshVersionTokens(
        IntegrationExternalMapping $mapping,
        ?string $externalVersionToken,
        ?string $localVersionToken,
    ): IntegrationExternalMapping {
        $affected = IntegrationExternalMapping::query()
            ->where('id', $mapping->id)
            ->whereNull('tombstoned_at')
            ->update([
                'external_version_token' => $externalVersionToken,
                'local_version_token' => $localVersionToken,
                'last_synced_at' => now(),
            ]);

        if ($affected === 0) {
            throw new RuntimeException(
                "Cannot refresh version tokens for mapping {$mapping->id}: it no longer exists or has been tombstoned."
            );
        }

        return $mapping->fresh();
    }

    /**
     * Tombstones a mapping (frozen-design-post-review.md §8's 4
     * reasons) — the ONLY legitimate way to end a mapping's "live"
     * lifecycle. The historical row is retained for audit (permanent
     * retention, never hard-deleted).
     */
    public function tombstone(IntegrationExternalMapping $mapping, string $reason): IntegrationExternalMapping
    {
        $mapping->update([
            'tombstoned_at' => now(),
            'tombstone_reason' => $reason,
        ]);

        return $mapping->fresh();
    }

    private function findLiveByExternalId(int $firmIntegrationId, string $resourceType, string $externalId): ?IntegrationExternalMapping
    {
        return IntegrationExternalMapping::query()
            ->where('firm_integration_id', $firmIntegrationId)
            ->where('resource_type', $resourceType)
            ->where('external_id', $externalId)
            ->whereNull('tombstoned_at')
            ->first();
    }
}
