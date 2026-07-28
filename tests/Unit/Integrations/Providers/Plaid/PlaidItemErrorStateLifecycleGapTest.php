<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\ProviderConnectionService;
use ReflectionClass;
use Tests\TestCase;

/**
 * PlaidItemErrorStateLifecycleGapTest — FirmsVault Live Integrations,
 * Checkpoint 4 (Plaid financial evidence add-on).
 *
 * PRODUCTION DEFECT, FOUND BY THIS TEST-WRITER PASS AND FIXED BY THE
 * IMPLEMENTER. The frozen design's §6 ("Item error-state handling and
 * update-mode re-authentication", `checkpoint4-design-plaid-provider-core.md`)
 * specifies THREE public methods on `ProviderConnectionService` —
 * `markItemErrorState()`, `markItemLoginRepaired()`, and
 * `initiateLinkTokenUpdateMode()` — none of which existed anywhere in
 * the codebase when this file was originally written, and no listener
 * consumed a verified `lifecycle:item_*` webhook event at all (the
 * codebase's one existing listener, `DispatchPullSyncOnVerifiedWebhookEvent`,
 * deliberately skips every `lifecycle:*`-prefixed event_type by design —
 * it exists for a different, provider-agnostic concern). As originally
 * shipped, a Plaid Item that transitioned to `ITEM_LOGIN_REQUIRED` at
 * the bank would never have updated `firm_integrations.status` at all.
 *
 * FIX: all three methods are now implemented on `ProviderConnectionService`
 * (see that file's own docblocks), and a new, Plaid-specific listener —
 * `App\Integrations\Listeners\DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent`,
 * dispatched from the same `InboundWebhookController` call site as
 * `DispatchPullSyncOnVerifiedWebhookEvent` — now consumes verified
 * `lifecycle:item_error`/`lifecycle:item_user_permission_revoked`/
 * `lifecycle:item_user_account_revoked`/`lifecycle:item_login_repaired`
 * events and calls the appropriate transition method. All tests in this
 * file now pass against the fixed code.
 */
final class PlaidItemErrorStateLifecycleGapTest extends TestCase
{
    public function test_provider_connection_service_declares_mark_item_error_state(): void
    {
        $this->assertTrue(
            method_exists(ProviderConnectionService::class, 'markItemErrorState'),
            'checkpoint4-design-plaid-provider-core.md §6 requires ProviderConnectionService::markItemErrorState('.
            'FirmIntegration $connection, string $plaidErrorCode): FirmIntegration — mapping ITEM_LOGIN_REQUIRED/'.
            'USER_PERMISSION_REVOKED/USER_ACCOUNT_REVOKED/OAUTH_INVALID_TOKEN/OAUTH_CONSENT_EXPIRED/OAUTH_USER_REVOKED '.
            'onto ConnectionStatus::ReauthorizationRequired. This method does not exist anywhere in the shipped code.'
        );
    }

    public function test_provider_connection_service_declares_mark_item_login_repaired(): void
    {
        $this->assertTrue(
            method_exists(ProviderConnectionService::class, 'markItemLoginRepaired'),
            'checkpoint4-design-plaid-provider-core.md §6 requires ProviderConnectionService::markItemLoginRepaired('.
            'FirmIntegration $connection): FirmIntegration — the symmetric ReauthorizationRequired -> Active transition '.
            'driven by the ITEM: LOGIN_REPAIRED webhook. This method does not exist anywhere in the shipped code.'
        );
    }

    public function test_provider_connection_service_declares_initiate_link_token_update_mode(): void
    {
        $this->assertTrue(
            method_exists(ProviderConnectionService::class, 'initiateLinkTokenUpdateMode'),
            'checkpoint4-design-plaid-provider-core.md §6 requires ProviderConnectionService::initiateLinkTokenUpdateMode('.
            'FirmIntegration $connection, int $currentUserId): LinkTokenInitiationResult — the update-mode re-authentication '.
            'entry point. This method does not exist anywhere in the shipped code, even though PlaidProvider::createLinkToken() '.
            'itself already correctly implements the update_access_token branch this caller would drive '.
            '(see PlaidProviderLinkTokenTest.php\'s own update-mode tests).'
        );
    }

    /**
     * Confirms the real-world consequence, not just the missing
     * signature: no listener class anywhere in this codebase's
     * `App\Integrations\Listeners` namespace ever handles a
     * `lifecycle:item_*` event_type — the one place `markItemErrorState()`
     * would actually need to be called FROM, per the design's own §6
     * closing paragraph ("a new, Plaid-specific listener on verified
     * ITEM: ERROR webhook events").
     */
    public function test_no_listener_class_exists_anywhere_that_consumes_a_lifecycle_item_prefixed_webhook_event(): void
    {
        $listenerDirectory = app_path('Integrations/Listeners');
        $this->assertDirectoryExists($listenerDirectory);

        $listenerFiles = glob($listenerDirectory.'/*.php');
        $this->assertNotFalse($listenerFiles);

        $matchingListener = null;

        foreach ($listenerFiles as $file) {
            $source = file_get_contents($file);

            if ($source !== false && str_contains($source, 'markItemErrorState')) {
                $matchingListener = $file;

                break;
            }
        }

        $this->assertNotNull(
            $matchingListener,
            'No listener class under app/Integrations/Listeners/ calls markItemErrorState() (because it does not exist) — '.
            'a verified lifecycle:item_error webhook event is durably recorded but never actually acted on anywhere in this codebase.'
        );
    }

    /**
     * A structural cross-check: PlaidProvider itself already correctly
     * emits the `lifecycle:item_error` event_type for an ITEM/ERROR
     * webhook (proven independently in PlaidProviderWebhookTest.php) —
     * the gap is entirely on the CONSUMING side
     * (ProviderConnectionService/a listener), never in PlaidProvider's
     * own webhook-parsing mechanics. Documented here so a future reader
     * does not mistake this file's failures as evidence that
     * PlaidProvider.php itself needs a fix.
     */
    public function test_plaid_provider_itself_correctly_emits_the_item_error_event_type_the_missing_consumer_would_need(): void
    {
        $result = app(PlaidProvider::class)->parseInboundEvent(json_encode([
            'webhook_type' => 'ITEM',
            'webhook_code' => 'ERROR',
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertSame('lifecycle:item_error', $result['event_type'], 'PlaidProvider\'s own event_type construction is correct — confirming the gap this file documents is entirely on the consuming side.');
    }

    /**
     * Documents the exact mapping `markItemErrorState()` implements, per
     * the design's own binding table — kept here as a single source of
     * truth, and now cross-checked directly against the real
     * implementation's own `REAUTHORIZATION_REQUIRED_PLAID_ERROR_CODES`
     * constant via reflection, so this table can never silently drift
     * from the shipped mapping.
     */
    public function test_the_designs_own_reauthorization_required_error_code_mapping_table_is_documented_here_for_the_future_implementer(): void
    {
        $reauthorizationRequiredCodes = [
            'ITEM_LOGIN_REQUIRED',
            'USER_PERMISSION_REVOKED',
            'USER_ACCOUNT_REVOKED',
            'OAUTH_INVALID_TOKEN',
            'OAUTH_CONSENT_EXPIRED',
            'OAUTH_USER_REVOKED',
        ];
        $healthSignalOnlyCodes = ['PENDING_EXPIRATION', 'PENDING_DISCONNECT'];

        $this->assertCount(6, $reauthorizationRequiredCodes);
        $this->assertCount(2, $healthSignalOnlyCodes);
        $this->assertEmpty(array_intersect($reauthorizationRequiredCodes, $healthSignalOnlyCodes), 'These two sets must never overlap — every Plaid Item error code maps to exactly one bucket.');

        $reflection = new ReflectionClass(ProviderConnectionService::class);

        $this->assertTrue($reflection->hasMethod('markItemErrorState'), 'markItemErrorState() is now implemented.');

        $constant = $reflection->getConstant('REAUTHORIZATION_REQUIRED_PLAID_ERROR_CODES');
        $this->assertIsArray($constant);
        sort($constant);
        $expected = $reauthorizationRequiredCodes;
        sort($expected);
        $this->assertSame($expected, $constant, 'ProviderConnectionService::REAUTHORIZATION_REQUIRED_PLAID_ERROR_CODES must match this test\'s own documented table exactly.');
    }
}
