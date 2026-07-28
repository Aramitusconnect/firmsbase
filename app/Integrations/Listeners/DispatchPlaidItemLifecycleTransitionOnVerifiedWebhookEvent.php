<?php

declare(strict_types=1);

namespace App\Integrations\Listeners;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\ProviderConnectionService;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent —
 * FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial evidence
 * add-on" §6 — "Item error-state handling and update-mode
 * re-authentication"). Closes the exact gap
 * `PlaidItemErrorStateLifecycleGapTest.php` documents: as originally
 * shipped, `ProviderConnectionService::markItemErrorState()`/
 * `markItemLoginRepaired()` had no caller anywhere in this codebase — a
 * verified `lifecycle:item_*` webhook event was durably recorded but
 * never actually acted on.
 *
 * Dispatched from the SAME call site as
 * `DispatchPullSyncOnVerifiedWebhookEvent` — see that class's own
 * docblock for why a direct `::dispatch()` call, not a framework
 * Illuminate event, is the only hook point that actually fires
 * (`InboundWebhookEventService::recordVerifiedEvent()` writes via a raw
 * `DB::table(...)` insert, which never fires an Eloquent `created`
 * event).
 *
 * Plaid-specific by construction (unlike its sibling, which is
 * deliberately provider-agnostic) — the `lifecycle:item_*` event-type
 * vocabulary this listener consumes is itself Plaid-specific
 * (`PlaidProvider::parseInboundEvent()`'s own `lifecycle:item_`.strtolower($webhookCode)`
 * construction), so branching on provider identity here is the correct
 * design, not a violation of the provider-agnostic convention its
 * sibling establishes for the genuinely-shared sync-dispatch concern.
 *
 * NEVER reads a Plaid error code out of the raw webhook body/job
 * payload: `PlaidProvider::fetchItemErrorCode()`'s own docblock explains
 * why (this codebase's "never trust job payload, re-verify fresh from
 * the provider" discipline, and the absence of any per-provider
 * payload-field allowlist mechanism to safely thread one through
 * `InboundWebhookController` in the first place). For
 * `lifecycle:item_user_permission_revoked`/`lifecycle:item_user_account_revoked`,
 * the Plaid error code IS the event_type itself (re-derived by exact
 * string match, not guessed) — safe to trust directly because
 * `event_type` is only ever constructed by `parseInboundEvent()` AFTER
 * `verifyInboundSignature()` has already cryptographically verified the
 * ENTIRE raw webhook body, unlike an arbitrary nested JSON field value.
 *
 * Scalar-FK/string-only constructor, matching this codebase's
 * "every ShouldQueue job carries only scalar/enum/DateTimeInterface
 * constructor parameters" convention (JobConstructorsCarryOnlyScalarSecretSafeTypesTest).
 */
final class DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 3;

    private const DIRECTLY_TRUSTED_ERROR_CODE_EVENT_TYPES = [
        'lifecycle:item_user_permission_revoked' => 'USER_PERMISSION_REVOKED',
        'lifecycle:item_user_account_revoked' => 'USER_ACCOUNT_REVOKED',
    ];

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $providerKey,
        public readonly ?string $eventType,
        public readonly int $webhookEventId,
    ) {}

    public function handle(ProviderRegistry $registry, ProviderConnectionService $connections): void
    {
        if ($this->providerKey !== ProviderKey::Plaid->value) {
            return;
        }

        $this->runInFirmContext($this->firmId, function () use ($registry, $connections): void {
            // Re-verify fresh, past-dispatch-time — never trust anything
            // carried in the job payload itself (mirrors
            // DispatchPullSyncOnVerifiedWebhookEvent's identical Gate 1).
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->first();

            if ($connection === null || $connection->status === ConnectionStatus::Disconnected) {
                return;
            }

            $provider = $registry->get(ProviderKey::Plaid);

            if (! $provider instanceof PlaidProvider) {
                // Structurally impossible in production (this codebase's
                // registry always resolves ProviderKey::Plaid to
                // PlaidProvider), kept as a defensive, never-throwing
                // no-op rather than an assumption.
                return;
            }

            if ($this->eventType === 'lifecycle:item_error') {
                $errorCode = $provider->fetchItemErrorCode($connection);

                if ($errorCode === null) {
                    Log::info('DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent: item_error webhook received but /item/get reported no error; skipping.', [
                        'firm_integration_id' => $this->firmIntegrationId,
                        'webhook_event_id' => $this->webhookEventId,
                    ]);

                    return;
                }

                $connections->markItemErrorState($connection, $errorCode);

                return;
            }

            if (array_key_exists((string) $this->eventType, self::DIRECTLY_TRUSTED_ERROR_CODE_EVENT_TYPES)) {
                $connections->markItemErrorState($connection, self::DIRECTLY_TRUSTED_ERROR_CODE_EVENT_TYPES[$this->eventType]);

                return;
            }

            if ($this->eventType === 'lifecycle:item_login_repaired') {
                $connections->markItemLoginRepaired($connection);

                return;
            }

            // Every other lifecycle:item_* event_type (pending_expiration,
            // pending_disconnect, webhook_update_acknowledged,
            // unrecognized_webhook) is health-signal-only per the
            // design's own binding table — no status transition.
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent: failed to apply an Item lifecycle transition.', [
            'firm_integration_id' => $this->firmIntegrationId,
            'provider_key' => $this->providerKey,
            'webhook_event_id' => $this->webhookEventId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
