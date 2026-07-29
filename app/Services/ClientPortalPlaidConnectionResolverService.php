<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ClientPortalPlaidConnectionResolverService — the ONE server-side
 * resolution boundary every Client Portal page uses to answer "which
 * `firm_integrations` row is this matter's client currently allowed to
 * act on?"
 *
 * FOUND AND FIXED (release-candidate remediation, defects C1 and H1).
 * Two Client Portal pages each answered that question their own way,
 * and both answers were wrong:
 *
 *   - `PlaidDateRangeConfirmationPage::continueToConsent()` (C1,
 *     Critical) resolved the connection as "the firm's most recently
 *     created Active Plaid connection"
 *     (`where('firm_id', ...)->where('status', 'active')->latest('id')->first()`).
 *     Ordinary concurrent use — a second client of the same firm
 *     starting their own Link flow — silently re-pointed the first
 *     client's `financial_evidence_matter_authorizations` row at the
 *     SECOND client's bank connection, binding one client's authorized
 *     retrieval window to another client's financial account. No
 *     attacker was required: creation order alone decided the binding.
 *   - `PlaidConsentPage::resolveConnectionOrFail()` (H1, High) trusted
 *     a client-suppliable `#[Url] $firmIntegration` Livewire property,
 *     validated only against `firm_id` + provider — never against the
 *     current matter's own request. Any authenticated portal client
 *     could edit one query-string integer and record a consent (or, via
 *     the revoke header action, a disconnect) against a DIFFERENT
 *     matter's connection inside the same firm — the exact IDOR already
 *     closed in `PlaidExchangeController::exchange()`.
 *
 * The single correct source of truth is
 * `financial_evidence_matter_requests.firm_integration_id` — the
 * server-authoritative binding `PlaidAccountSelectionPage::mount()`
 * persists at the moment it creates the connection FOR one specific
 * request, before the client ever sees a `firm_integration_id` at all
 * (see that column's own migration docblock for the IDOR it exists to
 * close). This service is that column's shared reader, so the two pages
 * and `PlaidExchangeController` can never drift apart again.
 *
 * NEVER: `latest()`/`first()` over `firm_integrations`, "the firm's
 * first/newest Active connection," or a client-submitted connection id
 * as the binding SOURCE. A client-submitted id may still travel for UX
 * (the consent page's URL keeps one so a bookmarked link renders), but
 * it is only ever cross-CHECKED against the server's own value and
 * rejected on mismatch — the identical defense-in-depth shape
 * `PlaidExchangeController::exchange()` already uses.
 *
 * Fails CLOSED: every unresolvable, mismatched, revoked, cancelled, or
 * unsupported-state binding throws a 403/404-shaped exception rather
 * than falling through to "no connection found, carry on."
 *
 * Every denial is audited through the existing
 * `TimelineEventRecorder`, with metadata run through the
 * forbidden-key denylist below — modeled directly on
 * `App\Integrations\Services\InboundWebhookAuditLogger`'s
 * `FORBIDDEN_CONTEXT_KEYS` discipline. Only internal row ids, an action
 * name, and a fixed reason code are ever recorded: never a token, never
 * an account number/mask, never a request body.
 */
final class ClientPortalPlaidConnectionResolverService
{
    /**
     * A Client Portal actor was refused the connection they tried to act
     * on. Deliberately ONE event type with a machine-readable `reason`
     * in metadata, rather than six near-identical event names.
     */
    public const DENIAL_EVENT_TYPE = 'financial_evidence.client_portal_connection_binding_denied';

    /**
     * A Client Portal client successfully revoked their own connection
     * (defect H5's success path). Recorded by `PlaidConsentPage` in
     * ADDITION to — never instead of — the
     * `integration_oauth.disconnect`/`integration_oauth.credential_revoked`
     * events `ProviderConnectionService::disconnect()` already writes.
     */
    public const REVOCATION_EVENT_TYPE = 'financial_evidence.client_portal_connection_revoked';

    /**
     * Defense-in-depth denylist, matched case-insensitively against
     * metadata keys, mirroring InboundWebhookAuditLogger's identical
     * control. Never a substitute for "callers must not pass secret
     * material in the first place" — the callers in this codebase pass
     * only ids and reason codes.
     *
     * @var string[]
     */
    private const FORBIDDEN_METADATA_KEYS = [
        'access_token', 'refresh_token', 'public_token', 'link_token', 'token', 'tokens',
        'secret', 'plaintext_secret', 'webhook_secret', 'password', 'authorization', 'cookie',
        'account_number', 'account_numbers', 'routing_number', 'mask', 'account_mask', 'iban',
        'balance', 'balances', 'raw_body', 'body', 'payload', 'headers', 'request_body',
    ];

    public function __construct(
        private readonly ClientPortalMatterAccessPolicyService $matterAccess,
        private readonly TimelineEventRecorder $events,
    ) {}

    /**
     * Resolves the connection this portal client may act on for this
     * matter, or throws. Verifies, in order: the acting client's own
     * firm matches the matter's firm; the client still holds a live
     * `client_portal_matter_grants` row for the matter (re-checked here
     * even though every caller already checked it — the resolve step is
     * the boundary, never the caller's earlier filter); the matter has a
     * live, non-cancelled `financial_evidence_matter_requests` row
     * carrying a server-set `firm_integration_id`; that connection
     * exists, belongs to the SAME firm, is a Plaid connection, and is in
     * one of the caller's explicitly supported states; and — when the
     * caller passes one — that any client-supplied id equals the
     * server-resolved id exactly.
     *
     * @param  ConnectionStatus[]  $allowedStatuses  the states this specific
     *                                               caller supports — never
     *                                               widened by this service
     * @param  string  $action  short action name recorded on a denial
     * @param  int|string|null  $clientSuppliedFirmIntegrationId  cross-checked,
     *                                                            never trusted
     * @return array{0: FinancialEvidenceMatterRequest, 1: FirmIntegration}
     */
    public function resolveOrFail(
        ClientPortalUser $portalUser,
        Matter $matter,
        array $allowedStatuses,
        string $action,
        int|string|null $clientSuppliedFirmIntegrationId = null,
    ): array {
        [$request, $connection, $reason, $submittedId, $boundId] = $this->attempt(
            $portalUser,
            $matter,
            $allowedStatuses,
            $clientSuppliedFirmIntegrationId,
        );

        if ($reason !== null) {
            $this->recordDenial($matter, $portalUser, $action, $reason, $submittedId, $boundId);

            throw $reason === 'no_request_bound_to_a_connection'
                ? new NotFoundHttpException('No financial connection is currently linked to this matter.')
                : new AccessDeniedHttpException('You are not authorized to act on this financial connection.');
        }

        return [$request, $connection];
    }

    /**
     * Same resolution, no audit and no exception — for pure UI-visibility
     * probes (e.g. "should the Revoke button render at all?"), which run
     * on every page render and must not write a denial row every time a
     * client simply has no connection yet. Never a substitute for
     * `resolveOrFail()`: any action that actually DOES something
     * re-resolves through that method.
     */
    public function canResolve(
        ClientPortalUser $portalUser,
        Matter $matter,
        array $allowedStatuses,
        int|string|null $clientSuppliedFirmIntegrationId = null,
    ): bool {
        return $this->attempt($portalUser, $matter, $allowedStatuses, $clientSuppliedFirmIntegrationId)[2] === null;
    }

    /**
     * The single resolution implementation both public entry points
     * share, so a visibility probe can never disagree with the action's
     * own authorization decision.
     *
     * @param  ConnectionStatus[]  $allowedStatuses
     * @return array{0: ?FinancialEvidenceMatterRequest, 1: ?FirmIntegration, 2: ?string, 3: ?int, 4: ?int}
     */
    private function attempt(
        ClientPortalUser $portalUser,
        Matter $matter,
        array $allowedStatuses,
        int|string|null $clientSuppliedFirmIntegrationId,
    ): array {
        $firmId = (int) $matter->firm_id;
        $submittedId = $clientSuppliedFirmIntegrationId === null || $clientSuppliedFirmIntegrationId === ''
            ? null
            : (int) $clientSuppliedFirmIntegrationId;

        if ($firmId === 0 || (int) ($portalUser->client?->firm_id ?? 0) !== $firmId) {
            return [null, null, 'acting_client_firm_mismatch', $submittedId, null];
        }

        /** @var array{0: ?FinancialEvidenceMatterRequest, 1: ?FirmIntegration, 2: bool} $resolved */
        $resolved = (new TenantContextService)->runWithFirmContext($firmId, function () use ($portalUser, $matter, $firmId): array {
            if (! $this->matterAccess->canAccessMatter($portalUser, $matter)) {
                return [null, null, false];
            }

            $request = FinancialEvidenceMatterRequest::query()
                ->where('firm_id', $firmId)
                ->where('matter_id', $matter->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('cancelled_at')
                ->whereNotNull('firm_integration_id')
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->first();

            if ($request === null) {
                return [null, null, true];
            }

            $connection = FirmIntegration::query()
                ->with('integrationProvider')
                ->where('id', $request->firm_integration_id)
                ->where('firm_id', $firmId)
                ->first();

            return [$request, $connection, true];
        });

        [$request, $connection, $hasMatterGrant] = $resolved;

        if (! $hasMatterGrant) {
            return [null, null, 'no_live_matter_grant', $submittedId, null];
        }

        if ($request === null) {
            return [null, null, 'no_request_bound_to_a_connection', $submittedId, null];
        }

        if ($connection === null) {
            // The bound id exists on the request but resolves to nothing
            // inside this firm — a cross-firm id, or a deleted row.
            return [null, null, 'bound_connection_not_in_this_firm', $submittedId, (int) $request->firm_integration_id];
        }

        if ($connection->providerKey() !== ProviderKey::Plaid) {
            return [null, null, 'bound_connection_is_not_plaid', $submittedId, (int) $connection->id];
        }

        if (! in_array($connection->status, $allowedStatuses, true)) {
            return [null, null, 'bound_connection_state_not_supported', $submittedId, (int) $connection->id];
        }

        if ($submittedId !== null && $submittedId !== (int) $connection->id) {
            // Defense in depth, exactly as PlaidExchangeController does:
            // a mismatch means either a stale client or an id aimed at
            // another matter's connection — both rejected identically.
            return [null, null, 'submitted_connection_id_does_not_match_binding', $submittedId, (int) $connection->id];
        }

        return [$request, $connection, null, $submittedId, (int) $connection->id];
    }

    /**
     * Records a Client Portal financial-evidence event through the
     * existing TimelineEventRecorder, with the forbidden-key denylist
     * applied. Exposed so callers never reach for the recorder (and its
     * unfiltered metadata array) directly.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvent(
        Matter $matter,
        string $eventType,
        array $metadata = [],
        ?User $actorUser = null,
    ): void {
        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $eventType, $metadata, $actorUser): void {
            $firm = Firm::query()->find($matter->firm_id);

            if ($firm === null) {
                return;
            }

            $this->events->record($firm, $eventType, $matter, $actorUser, $this->sanitizeMetadata($metadata));
        });
    }

    /**
     * Recorded on an ordinary (not `independentOfAmbientTransaction`)
     * write deliberately: every call site is a Livewire action/page
     * method invoked directly from the request lifecycle with no ambient
     * transaction of its own, so the event commits normally on its own
     * `runWithFirmContext()` transaction BEFORE `resolveOrFail()`'s
     * throw unwinds anything — the separate-`pgsql_audit`-connection
     * technique IntegrationAccessPolicyService needs (it is called from
     * inside a service-level transaction) is not required here, and
     * would drag its own committed-fixture precondition along with it.
     */
    private function recordDenial(
        Matter $matter,
        ClientPortalUser $portalUser,
        string $action,
        string $reason,
        ?int $submittedId,
        ?int $boundId,
    ): void {
        $this->recordEvent($matter, self::DENIAL_EVENT_TYPE, [
            'action' => $action,
            'reason' => $reason,
            'matter_id' => (int) $matter->id,
            'client_id' => $portalUser->client_id === null ? null : (int) $portalUser->client_id,
            'client_portal_user_id' => (int) $portalUser->id,
            'submitted_firm_integration_id' => $submittedId,
            'bound_firm_integration_id' => $boundId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_METADATA_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }
}
