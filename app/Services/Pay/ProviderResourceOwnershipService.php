<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Exceptions\Pay\ProviderResourceOwnershipConflictException;
use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ProviderResourceOwnershipService — FirmsVault Pay Gate A2
 * (v1.4 §5/§6/§7/§39). The SOLE writer and SOLE resolver of
 * provider-resource tenant ownership.
 *
 * ============================================================
 * WHY THIS IS NOT A SECOND OWNERSHIP AUTHORITY
 * ============================================================
 * It writes to `integration_webhook_routing_index` — the SAME table the
 * existing webhook path already uses — in that table's new
 * provider-resource addressing mode. There is exactly one table, one
 * unique index and one resolver for "which firm owns this external
 * provider resource", which is precisely what §6 requires. Nothing else
 * in FirmsVault Pay may assign tenant ownership; business-resource
 * mappings (ProviderCommand, PaymentAttempt, PaymentRefund) associate
 * an ALREADY-OWNED resource with internal objects and can never
 * establish or override ownership.
 * ============================================================
 *
 * SECURITY PROPERTIES OF THE PRE-TENANT READ (§39). resolveOwner() runs
 * outside tenant RLS, exactly like the existing
 * WebhookConnectionResolverService::resolveConnectionIdentity(), and is
 * bounded in the same way:
 *
 *   - it returns ONLY {firm_id, firm_integration_id,
 *     integration_provider_id, provider_key} — never a secret, never
 *     connection metadata, never a hydrated model;
 *   - it touches exactly one table, which contains no credential
 *     material of any kind;
 *   - it grants NO access to payments, clients, matters, journals or
 *     refunds. It is a lookup, not a gateway. Every tenant financial
 *     read that follows must still establish real firm context and
 *     satisfy FORCE RLS;
 *   - it collapses every non-resolvable case to null, so a caller
 *     cannot distinguish "unknown provider" from "unknown resource"
 *     from "inactive ownership" — the same anti-enumeration discipline
 *     the existing resolver documents.
 *
 * IMMUTABILITY (§7). establishOwnership() is INSERT-ONLY. There is no
 * method here that reassigns ownership, and there never may be:
 *   - the partial unique index makes a competing INSERT fail even when
 *     the existing row is inactive (so deactivate-then-reclaim is
 *     blocked in the database);
 *   - the model's own updating/deleting guards block in-place mutation
 *     and deletion.
 * deactivate() flips ownership_status only, which is the one permitted
 * ACTIVE -> INACTIVE transition.
 */
class ProviderResourceOwnershipService
{
    public function __construct(
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Establish tenant ownership of an external provider resource.
     *
     * Idempotent for the SAME owner: re-establishing an identical
     * ownership row returns the existing row rather than failing, so a
     * retried webhook or a replayed provisioning step is safe.
     *
     * Conflicting for a DIFFERENT owner: throws
     * ProviderResourceOwnershipConflictException. This is the exception
     * FV-A-039's losing concurrent writer receives.
     */
    public function establishOwnership(
        int $firmId,
        int $firmIntegrationId,
        int $integrationProviderId,
        string $providerResourceType,
        string $providerResourceId,
    ): IntegrationWebhookRoutingIndex {
        try {
            // SAVEPOINT ISOLATION — see ProviderCommandService::
            // createOrReuse() for the full reasoning. In PostgreSQL a
            // failed statement aborts the entire enclosing transaction,
            // so without a savepoint the arbitration read below could
            // not run when this is called inside a caller's transaction.
            $row = DB::transaction(fn (): IntegrationWebhookRoutingIndex => IntegrationWebhookRoutingIndex::query()->create([
                'firm_id' => $firmId,
                'firm_integration_id' => $firmIntegrationId,
                'integration_provider_id' => $integrationProviderId,
                'webhook_routing_token_hash' => null,
                'provider_resource_type' => $providerResourceType,
                'provider_resource_id' => $providerResourceId,
                'ownership_status' => 'active',
                'ownership_established_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->arbitrateExistingOwnership(
                $firmId,
                $firmIntegrationId,
                $integrationProviderId,
                $providerResourceType,
                $providerResourceId,
            );
        }

        $this->audit->record(PayAuditRecorder::OWNERSHIP_ESTABLISHED, $firmId, [
            'firm_integration_id' => $firmIntegrationId,
            'provider_resource_type' => $providerResourceType,
            'provider_resource_id' => $providerResourceId,
        ]);

        return $row;
    }

    /**
     * Reached only when the unique index rejected an insert, so an
     * owning row provably exists.
     */
    private function arbitrateExistingOwnership(
        int $firmId,
        int $firmIntegrationId,
        int $integrationProviderId,
        string $providerResourceType,
        string $providerResourceId,
    ): IntegrationWebhookRoutingIndex {
        /** @var IntegrationWebhookRoutingIndex|null $existing */
        $existing = IntegrationWebhookRoutingIndex::query()
            ->where('integration_provider_id', $integrationProviderId)
            ->where('provider_resource_type', $providerResourceType)
            ->where('provider_resource_id', $providerResourceId)
            ->first();

        if ($existing === null) {
            throw new ProviderResourceOwnershipConflictException($providerResourceType, $providerResourceId);
        }

        $sameOwner = (int) $existing->firm_id === $firmId
            && (int) $existing->firm_integration_id === $firmIntegrationId;

        if (! $sameOwner) {
            $this->audit->record(PayAuditRecorder::OWNERSHIP_CONFLICT, $firmId, [
                'provider_resource_type' => $providerResourceType,
                'provider_resource_id' => $providerResourceId,
                'attempted_firm_integration_id' => $firmIntegrationId,
            ]);

            throw new ProviderResourceOwnershipConflictException($providerResourceType, $providerResourceId);
        }

        return $existing;
    }

    /**
     * Pre-tenant resolution: external provider resource -> owning firm
     * and provider account. Returns null for every non-usable case.
     *
     * Only ACTIVE ownership resolves. An inactive row still occupies the
     * resource identity (so it can never be reassigned) but no longer
     * routes anything.
     */
    public function resolveOwner(
        int $integrationProviderId,
        string $providerResourceType,
        string $providerResourceId,
        ?string $providerKey = null,
    ): ?ResolvedWebhookConnection {
        $row = DB::table('integration_webhook_routing_index')
            ->where('integration_provider_id', $integrationProviderId)
            ->where('provider_resource_type', $providerResourceType)
            ->where('provider_resource_id', $providerResourceId)
            ->where('ownership_status', 'active')
            ->first();

        if ($row === null) {
            return null;
        }

        return new ResolvedWebhookConnection(
            firmId: (int) $row->firm_id,
            firmIntegrationId: (int) $row->firm_integration_id,
            integrationProviderId: (int) $row->integration_provider_id,
            providerKey: $providerKey ?? '',
        );
    }

    /**
     * The ONE permitted ownership lifecycle change (§7):
     * ACTIVE -> INACTIVE. The row itself is never removed, so the
     * historical fact of who owned this resource stays provable and the
     * resource can never be claimed by another firm.
     */
    public function deactivate(IntegrationWebhookRoutingIndex $ownership): IntegrationWebhookRoutingIndex
    {
        $ownership->ownership_status = 'inactive';
        $ownership->save();

        return $ownership->refresh();
    }
}
