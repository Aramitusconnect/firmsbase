<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\ProviderBalanceSnapshot;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\SyncCursorService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PlaidProviderIdempotencyKeyDeterminismTest — release-candidate
 * security-review remediation ("secondary hardening opportunity"
 * follow-up to checkpoint8-remediation-report.md's own
 * "Dispositioned, not a defect" entry). Proves that every
 * `usageIdempotencyKey` `PlaidProvider` constructs is now derived from
 * STABLE, DURABLE state rather than the wall clock.
 *
 * Every key this class builds feeds TWO real mechanisms — the outbound
 * `Idempotency-Key` HTTP header `ProviderRequestExecutor::send()`
 * attaches to the Plaid request (a genuine anti-double-charge mechanism
 * on Plaid's own side), and
 * `IntegrationUsageRecorderService::recordOnce()`'s local
 * usage-record/reservation dedup. A wall-clock-derived key defeats both:
 * `now()->format('YmdHi')` changes on any retry that crosses a minute
 * boundary, and `now()->getTimestampMs()` changes on literally every
 * invocation.
 *
 * Assertions therefore read the key off the ACTUAL outbound request
 * header (`Http::recorded()`), never off an internal helper — this
 * proves the value Plaid would really receive, not a reimplementation
 * of the construction under test.
 *
 * Every test follows the same two-sided shape the review requires:
 *   (a) the SAME logical call, retried across a deliberately-crossed
 *       minute (and, where relevant, hour/day) boundary via
 *       `Carbon::setTestNow()`, produces an IDENTICAL key; and
 *   (b) two genuinely DIFFERENT logical calls (different account,
 *       different page cursor, different cursor version, different date
 *       window, different payload, different credential, different
 *       webhook delivery) produce DIFFERENT keys — the fix must not
 *       collapse legitimately-distinct operations onto one key merely to
 *       remove the wall clock.
 *
 * `tearDown()` restores `Carbon::setTestNow(null)`. This codebase has a
 * known bug class where forgetting that restore leaks a frozen clock
 * into every later test in the process — never remove it.
 */
final class PlaidProviderIdempotencyKeyDeterminismTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const ACCESS_TOKEN = 'access-sandbox-fixture-token-idempotency-0001';

    /**
     * Deliberately 30 seconds BEFORE a minute boundary that is also an
     * hour boundary — so a single 60-second step invalidates both
     * `now()->format('YmdHi')` and `now()->getTimestampMs()`, the two
     * exact shapes this remediation removed.
     */
    private const T1 = '2026-07-29 10:59:30';

    private const T2 = '2026-07-29 11:00:30';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.oauth_apps.plaid.client_id' => 'unit-test-plaid-client-id',
            'integrations.oauth_apps.plaid.secret' => 'unit-test-plaid-secret',
            'integrations.oauth_apps.plaid.webhook_url' => 'https://firmsvault.test/webhooks/plaid',
            'integrations.oauth_apps.plaid.item_routing_hmac_key' => str_repeat('k', 32),
            'integrations.provider_environments.'.ProviderKey::Plaid->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => ['default' => self::SANDBOX_BASE],
                'live_base_urls' => ['default' => self::SANDBOX_BASE],
            ],
        ]);

        $this->fakePlaid();
    }

    protected function tearDown(): void
    {
        // MANDATORY — see this class's own docblock. A leaked frozen
        // clock silently corrupts unrelated tests later in the process.
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------
    // pull() — /transactions/sync (the one incremental resource type)
    // ------------------------------------------------------------

    public function test_transactions_sync_key_is_identical_when_the_same_page_is_refetched_across_a_minute_boundary(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $this->makeCursor($firm, $connection, ResourceType::Transaction->value);

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, 'page-cursor-1'));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, 'page-cursor-1'));

        $keys = $this->idempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertSame(
            $keys[0],
            $keys[1],
            'Refetching the SAME page of the SAME sync (same connection, same cursor version, same page cursor) must reuse one idempotency key even across a minute boundary — that is exactly the retry the outbound Idempotency-Key header exists to collapse.'
        );
    }

    public function test_transactions_sync_key_differs_for_a_different_page_cursor(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $this->makeCursor($firm, $connection, ResourceType::Transaction->value);

        Carbon::setTestNow(Carbon::parse(self::T1));

        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, 'page-cursor-1'));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, 'page-cursor-2'));

        $keys = $this->idempotencyKeys();

        $this->assertCount(3, $keys);
        $this->assertCount(
            3,
            array_unique($keys),
            'Three different pages of a transactions sync are three genuinely different logical requests and must never share an idempotency key — collapsing them would suppress two real usage records and invite Plaid to replay page 1 for pages 2 and 3.'
        );
    }

    public function test_transactions_sync_key_changes_once_the_durable_cursor_version_advances(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $cursor = $this->makeCursor($firm, $connection, ResourceType::Transaction->value);
        $cursors = app(SyncCursorService::class);

        // The clock is deliberately FROZEN across this whole test, so
        // the only thing that can possibly change the key is the durable
        // cursor row — proving the identity really is read from
        // persisted state and not from the wall clock.
        Carbon::setTestNow(Carbon::parse(self::T1));

        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));

        // Exactly what PullSyncJob does once a page commits: claim the
        // cursor for the run, then advance() it (which bumps
        // cursor_version — the durable "this page is done" marker).
        $claimed = $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 4242));
        $this->assertNotNull($claimed);
        $this->runWithFirmContext($firm, fn () => $cursors->advance($connection, $cursor->id, $claimed->cursor_version, 'page-cursor-1'));

        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));

        $keys = $this->idempotencyKeys();

        $this->assertNotSame(
            $keys[0],
            $keys[1],
            'Once the cursor has advanced, a later sync is a genuinely new logical operation and must get a new key, even with the clock frozen.'
        );
    }

    // ------------------------------------------------------------
    // pull() — full-snapshot resource types
    // ------------------------------------------------------------

    public function test_full_snapshot_pull_key_is_identical_across_a_minute_boundary_and_changes_when_the_cursor_advances(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $cursor = $this->makeCursor($firm, $connection, ResourceType::BankAccount->value);
        $cursors = app(SyncCursorService::class);

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::BankAccount->value, null));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::BankAccount->value, null));

        $claimed = $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 99));
        $this->runWithFirmContext($firm, fn () => $cursors->advance($connection, $cursor->id, $claimed->cursor_version, null));

        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::BankAccount->value, null));

        $keys = $this->idempotencyKeys();

        $this->assertSame($keys[0], $keys[1], 'A retried /auth/get snapshot for the same un-advanced cursor must reuse one key across a minute boundary.');
        $this->assertNotSame($keys[1], $keys[2], 'The NEXT scheduled snapshot run — after the previous run advanced the cursor — is a different logical operation and must get a different key.');
    }

    public function test_every_pullable_resource_type_gets_its_own_distinct_key(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Carbon::setTestNow(Carbon::parse(self::T1));

        foreach ($this->provider()->pullableResourceTypes() as $resourceType) {
            $this->makeCursor($firm, $connection, $resourceType);
            $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], $resourceType, null));
        }

        $keys = $this->idempotencyKeys();

        // 7 pullable resource types, but Investment issues TWO calls
        // (/investments/holdings/get + /investments/transactions/get).
        $this->assertCount(8, $keys);
        $this->assertCount(
            8,
            array_unique($keys),
            'Each resource type (and each of the two investments endpoints) is a distinct logical operation and must never share an idempotency key with another.'
        );
    }

    public function test_investments_transactions_key_tracks_the_requested_date_window(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $this->makeCursor($firm, $connection, ResourceType::Investment->value);

        // Two calls in the same UTC day, an hour apart: same requested
        // start_date/end_date, therefore the same logical request.
        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Investment->value, null));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Investment->value, null));

        // The next UTC day: /investments/transactions/get genuinely asks
        // Plaid for a DIFFERENT window, so it must NOT reuse the key.
        Carbon::setTestNow(Carbon::parse(self::T1)->addDay());
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Investment->value, null));

        $keys = $this->idempotencyKeys();
        $requests = $this->recordedRequests();

        // Calls alternate holdings, transactions, holdings, transactions…
        $this->assertSame($keys[0], $keys[2], 'Holdings carries no date window at all, so it must be identical across a minute/hour boundary.');
        $this->assertSame($keys[1], $keys[3], '/investments/transactions/get within the same UTC day requests the identical window and must reuse one key.');
        $this->assertNotSame($keys[3], $keys[5], 'A different requested date window is a genuinely different request and must get a different key.');

        // The window folded into the key is provably the window actually
        // sent — never a separately-computed one.
        $this->assertSame('2026-07-29', $requests[1]->data()['end_date']);
        $this->assertSame('2024-07-29', $requests[1]->data()['start_date']);
        $this->assertSame('2026-07-30', $requests[5]->data()['end_date']);
        $this->assertSame('2024-07-30', $requests[5]->data()['start_date']);
    }

    // ------------------------------------------------------------
    // fetchBalance()
    // ------------------------------------------------------------

    public function test_balance_key_is_identical_across_a_minute_boundary_and_differs_per_account(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-a', []));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-a', []));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-b', []));

        $keys = $this->idempotencyKeys();

        $this->assertSame($keys[0], $keys[1], 'A retried Live Balance retrieval for the same account must reuse one key — this is the highest-value key in the class, since /accounts/balance/get is billed per request.');
        $this->assertNotSame($keys[1], $keys[2], 'Two different accounts are two different logical retrievals and must never share a key.');
    }

    public function test_balance_key_changes_once_a_confirmed_retrieval_snapshot_has_been_recorded(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        // Clock frozen for the whole test: the only thing that may move
        // the key is the durable provider_balance_snapshots row that
        // ProviderLiveBalanceConfirmationService writes ONLY on a
        // certain, billable outcome.
        Carbon::setTestNow(Carbon::parse(self::T1));

        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-a', []));

        $this->runWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'account_id' => 'account-a',
            'available_cents' => 100,
            'current_cents' => 100,
            'iso_currency_code' => 'USD',
            'retrieved_at' => Carbon::parse(self::T1),
        ]));

        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-a', []));

        $keys = $this->idempotencyKeys();

        $this->assertNotSame(
            $keys[0],
            $keys[1],
            'Once a retrieval has actually completed and been recorded, the NEXT confirmed retrieval is a new logical operation and must get a new key — even with the clock frozen.'
        );
    }

    // ------------------------------------------------------------
    // revokeAtProvider()
    // ------------------------------------------------------------

    public function test_revoke_key_is_identical_across_a_minute_boundary_and_changes_for_a_new_credential(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $credentials = app(IntegrationCredentialService::class);

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        // A later re-connect revokes the old credential and stores a
        // brand-new one — a genuinely different Item revocation.
        $this->runWithFirmContext($firm, function () use ($connection, $credentials) {
            $existing = $credentials->findActiveCredential($connection, CredentialType::ProviderAccessToken);
            $credentials->revoke($connection, $existing, 'test_reconnect');
        });
        $credentials->store($connection, CredentialType::ProviderAccessToken, 'access-sandbox-second-item-token');

        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $keys = $this->idempotencyKeys();

        $this->assertSame($keys[0], $keys[1], 'A re-attempted disconnect of the SAME Item/credential must reuse one key across a minute boundary.');
        $this->assertNotSame($keys[1], $keys[2], 'Revoking a different credential (a later re-connect) is a genuinely different revocation and must get a different key.');
    }

    // ------------------------------------------------------------
    // exchangePublicToken() + its best-effort /item/get follow-up
    // ------------------------------------------------------------

    public function test_public_token_exchange_and_its_institution_lookup_are_both_keyed_to_the_exchange_not_the_clock(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('public-sandbox-token-alpha', ['connection' => $connection]));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('public-sandbox-token-alpha', ['connection' => $connection]));

        // A genuinely different Link session always yields a different,
        // single-use public_token.
        $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('public-sandbox-token-beta', ['connection' => $connection]));

        $keys = $this->idempotencyKeys();

        // Each exchange issues TWO calls: the exchange itself, then the
        // best-effort /item/get institution lookup.
        $this->assertCount(6, $keys);
        $this->assertSame($keys[0], $keys[2], 'A retried exchange of the same public_token must reuse one key.');
        $this->assertSame($keys[1], $keys[3], 'The /item/get institution lookup is part of the SAME logical exchange and must reuse one key too — it used to carry now()->getTimestampMs(), which changed on every single invocation.');
        $this->assertNotSame($keys[2], $keys[4], 'A different Link session (different public_token) is a different exchange and must get a different key.');
        $this->assertNotSame($keys[3], $keys[5], 'A different Link session must likewise get a different institution-lookup key.');
        $this->assertNotSame($keys[0], $keys[1], 'The exchange and its follow-up lookup are two distinct billable calls and must never collapse onto one key.');
    }

    // ------------------------------------------------------------
    // fetchItemErrorCode()
    // ------------------------------------------------------------

    public function test_item_error_state_key_is_identical_across_listener_retries_and_differs_per_webhook_delivery(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        // The listener (DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent,
        // $tries = 3) re-runs this for ONE webhook delivery. Two of its
        // three attempts land in different minutes.
        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection, 8801));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection, 8801));

        // A genuinely different verified webhook delivery.
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection, 8802));

        $keys = $this->idempotencyKeys();

        $this->assertSame($keys[0], $keys[1], 'All three of the listener\'s attempts at ONE webhook delivery must share one key — this is the only site in the class whose caller genuinely retries.');
        $this->assertNotSame($keys[1], $keys[2], 'A different inbound webhook delivery is a different logical re-verification and must get a different key.');
    }

    public function test_item_error_state_key_falls_back_to_durable_connection_state_and_is_still_clock_independent(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection));

        $keys = $this->idempotencyKeys();

        $this->assertSame(
            $keys[0],
            $keys[1],
            'Without a caller-supplied webhook event id the key falls back to the connection\'s own durable lifecycle state — which must still be clock-independent.'
        );

        // …and the fallback must still move when the durable state the
        // operation feeds actually transitions. Only `error_reason` is
        // mutated here (not `status`): markItemErrorState() writes both,
        // but flipping the connection off Active would make
        // IntegrationCredentialService::decryptForOperation() refuse to
        // decrypt at all, so the call under test would never be reached.
        $this->runWithFirmContext($firm, fn () => $connection->forceFill([
            'error_reason' => 'ITEM_LOGIN_REQUIRED',
        ])->save());

        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection));

        $keys = $this->idempotencyKeys();
        $this->assertNotSame($keys[1], $keys[2], 'Once markItemErrorState() has transitioned the connection, a later re-verification is a new logical operation.');
    }

    // ------------------------------------------------------------
    // matchIdentity() / enrichTransactions()
    // ------------------------------------------------------------

    public function test_identity_match_key_is_a_pure_function_of_the_submitted_identity(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        $identity = ['legal_name' => 'Ada Lovelace', 'email_address' => 'ada@example.test'];

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->matchIdentity($connection, self::ACCESS_TOKEN, $identity));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->matchIdentity($connection, self::ACCESS_TOKEN, $identity));

        // Same payload, different key ORDER — still the same logical query.
        $this->runWithFirmContext($firm, fn () => $this->provider()->matchIdentity($connection, self::ACCESS_TOKEN, [
            'email_address' => 'ada@example.test', 'legal_name' => 'Ada Lovelace',
        ]));

        // A genuinely different person.
        $this->runWithFirmContext($firm, fn () => $this->provider()->matchIdentity($connection, self::ACCESS_TOKEN, [
            'legal_name' => 'Grace Hopper', 'email_address' => 'grace@example.test',
        ]));

        $keys = $this->idempotencyKeys();

        $this->assertSame($keys[0], $keys[1], 'The same identity scored against the same Item is the same logical query, whatever the clock says.');
        $this->assertSame($keys[1], $keys[2], 'Array key ORDER is not a logical difference — ksort() normalizes it.');
        $this->assertNotSame($keys[2], $keys[3], 'A different identity payload is a genuinely different match request.');
    }

    public function test_transactions_enrich_key_is_a_pure_function_of_the_submitted_batch(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        $batch = [
            ['id' => 'tx-1', 'description' => 'ACH DEBIT', 'amount' => 12.5, 'direction' => 'OUTFLOW'],
            ['id' => 'tx-2', 'description' => 'CARD PURCHASE', 'amount' => 4.25, 'direction' => 'OUTFLOW'],
        ];

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->enrichTransactions($connection, $batch));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->enrichTransactions($connection, $batch));

        $largerBatch = $batch;
        $largerBatch[] = ['id' => 'tx-3', 'description' => 'WIRE', 'amount' => 900.0, 'direction' => 'OUTFLOW'];
        $this->runWithFirmContext($firm, fn () => $this->provider()->enrichTransactions($connection, $largerBatch));

        $keys = $this->idempotencyKeys();

        $this->assertSame($keys[0], $keys[1], 'Enrichment is a pure function of the submitted batch — resubmitting the identical batch is exactly what a retry looks like.');
        $this->assertNotSame($keys[1], $keys[2], 'A batch with an extra transaction is a genuinely different request.');
    }

    // ------------------------------------------------------------
    // createLinkToken() — the ONE deliberate, documented exception
    // ------------------------------------------------------------

    /**
     * Regression guard for an INTENTIONAL decision, not an oversight.
     * `/link/token/create` mints a new, short-lived, single-use artifact
     * per user click and persists nothing, so there is no durable state
     * whose mutation could mark "this link token is used up". A
     * deterministic key here would let Plaid replay the FIRST (by then
     * expired) link_token into a later Link session and break it. Both
     * callers (ProviderConnectionService::initiateLinkTokenConnection()/
     * ::initiateLinkTokenUpdateMode()) are synchronous, user-initiated,
     * and never retried, so there is no retry to collapse either.
     *
     * The MODE, however, is a genuine logical distinction and is folded
     * in deterministically — asserted below so a future refactor cannot
     * silently merge new-Item and update-mode issuance.
     */
    public function test_link_token_create_is_deliberately_per_issuance_but_still_distinguishes_its_two_modes(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Carbon::setTestNow(Carbon::parse(self::T1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Transaction->value],
        ]));

        Carbon::setTestNow(Carbon::parse(self::T2));
        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Transaction->value],
        ]));

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'update_access_token' => self::ACCESS_TOKEN,
        ]));

        $keys = $this->idempotencyKeys();

        $this->assertNotSame($keys[0], $keys[1], 'Each Link launch legitimately mints its own token — see this test\'s docblock for why collapsing would be a functional regression, not a hardening.');
        $this->assertStringContainsString(':new_item:', $keys[0]);
        $this->assertStringContainsString(':update_mode:', $keys[2]);
    }

    // ------------------------------------------------------------
    // Cross-cutting
    // ------------------------------------------------------------

    /**
     * A blunt, whole-class guard: no key this provider emits may contain
     * anything that looks like a `now()->format('YmdHi')` stamp or a
     * `now()->getTimestampMs()` value — except the one deliberately
     * documented `plaid_link_token_create:` exception above.
     */
    public function test_no_provider_call_other_than_link_token_create_emits_a_wall_clock_key(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Carbon::setTestNow(Carbon::parse(self::T1));

        foreach ($this->provider()->pullableResourceTypes() as $resourceType) {
            $this->makeCursor($firm, $connection, $resourceType);
            $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], $resourceType, null));
        }

        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-a', []));
        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchItemErrorCode($connection, 1));
        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));
        $this->runWithFirmContext($firm, fn () => $this->provider()->enrichTransactions($connection, []));

        $minuteStamp = Carbon::parse(self::T1)->format('YmdHi');
        $millisecondStamp = (string) Carbon::parse(self::T1)->getTimestampMs();

        foreach ($this->idempotencyKeys() as $key) {
            $this->assertStringNotContainsString($minuteStamp, $key, "Idempotency key \"{$key}\" still embeds a wall-clock minute stamp.");
            $this->assertStringNotContainsString($millisecondStamp, $key, "Idempotency key \"{$key}\" still embeds a wall-clock millisecond stamp.");
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function provider(): PlaidProvider
    {
        return app(PlaidProvider::class);
    }

    /**
     * The outbound `Idempotency-Key` header of every request made so
     * far, in order — read off the REAL request
     * `ProviderRequestExecutor::send()` built, never off a
     * reimplementation of the key construction under test.
     *
     * @return string[]
     */
    private function idempotencyKeys(): array
    {
        return array_map(
            static fn (HttpClientRequest $request): string => $request->header('Idempotency-Key')[0] ?? '',
            $this->recordedRequests(),
        );
    }

    /**
     * @return HttpClientRequest[]
     */
    private function recordedRequests(): array
    {
        return Http::recorded()->map(static fn (array $pair): HttpClientRequest => $pair[0])->all();
    }

    private function fakePlaid(): void
    {
        Http::fake([
            self::SANDBOX_BASE.'/transactions/sync' => Http::response(['added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => null, 'has_more' => false], 200),
            self::SANDBOX_BASE.'/auth/get' => Http::response(['accounts' => [], 'numbers' => ['ach' => []]], 200),
            self::SANDBOX_BASE.'/identity/get' => Http::response(['accounts' => []], 200),
            self::SANDBOX_BASE.'/identity/match' => Http::response(['accounts' => []], 200),
            self::SANDBOX_BASE.'/liabilities/get' => Http::response(['liabilities' => ['credit' => [], 'mortgage' => [], 'student' => []]], 200),
            self::SANDBOX_BASE.'/investments/holdings/get' => Http::response(['holdings' => []], 200),
            self::SANDBOX_BASE.'/investments/transactions/get' => Http::response(['investment_transactions' => []], 200),
            self::SANDBOX_BASE.'/credit/bank_income/get' => Http::response(['bank_income' => []], 200),
            self::SANDBOX_BASE.'/statements/list' => Http::response(['statements' => []], 200),
            self::SANDBOX_BASE.'/accounts/balance/get' => Http::response(['accounts' => []], 200),
            self::SANDBOX_BASE.'/transactions/enrich' => Http::response(['enriched_transactions' => []], 200),
            self::SANDBOX_BASE.'/item/remove' => Http::response(['removed' => true], 200),
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-exchanged-token', 'item_id' => 'item-sandbox-1'], 200),
            self::SANDBOX_BASE.'/item/get' => Http::response(['item' => ['institution_id' => 'ins_109508'], 'error' => ['error_code' => 'ITEM_LOGIN_REQUIRED']], 200),
            self::SANDBOX_BASE.'/link/token/create' => Http::response(['link_token' => 'link-sandbox-token', 'expiration' => '2026-07-29T14:00:00Z'], 200),
        ]);
    }

    private function plaidProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Plaid->value]);
    }

    private function makeCursor(Firm $firm, FirmIntegration $connection, string $resourceType): mixed
    {
        return $this->runWithFirmContext($firm, fn () => app(SyncCursorService::class)
            ->firstOrCreate($connection, $resourceType, SyncDirection::Inbound));
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnectionWithAccessToken(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($this->plaidProviderRow())
            ->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => 'item-sandbox-1']));

        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)->store(
            $connection, CredentialType::ProviderAccessToken, self::ACCESS_TOKEN
        ));

        return [$firm, $connection];
    }
}
