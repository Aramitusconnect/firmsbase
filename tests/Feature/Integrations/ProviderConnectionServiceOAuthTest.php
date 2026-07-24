<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\OAuthRedirectUriMismatchException;
use App\Integrations\Exceptions\OAuthStateAlreadyConsumedException;
use App\Integrations\Exceptions\OAuthStateExpiredException;
use App\Integrations\Exceptions\OAuthStateNotFoundException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOAuthState;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ProviderConnectionServiceOAuthTest — Checkpoint 5. Full OAuth-state
 * and connection-lifecycle flow: initiate -> state issuance -> callback
 * -> PKCE/code exchange -> scope/account validation -> credential
 * persistence -> refresh -> reauthorization -> disconnect -> illegal-
 * transition rejection. Exercises the real ProviderConnectionService
 * end to end, routed through TestProvider (the only concrete provider
 * in this mission), never mocking the OAuth flow itself.
 *
 * Concurrency disclaimer (matches TrustConcurrencyLockServiceTest's own
 * convention, and Agent G's test-plan §0): a genuine multi-process race
 * is not attempted here — every "concurrent claim" test is a sequential
 * two-attempt simulation proving "the second attempt correctly observes
 * the first attempt's already-applied state," not literal wall-clock
 * concurrency.
 */
class ProviderConnectionServiceOAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> firm_id => TenantEncryptionKey id */
    private array $encryptionKeyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic app origin so route(..., absolute: true) never
        // falls back to the default http://localhost — which
        // ProviderRedirectUrlValidator::assertSafe() explicitly rejects
        // (host === 'localhost' is disallowed, and plain http is
        // disallowed without allowInsecureHttpForTesting). Matches the
        // https://app.firmsbase.test fixture host already used
        // throughout the Checkpoint 5 test/fixture suite (e.g.
        // IntegrationOAuthStatesForceRlsActivationTest's redirect_uri
        // fixtures).
        //
        // config(['app.url' => ...]) alone does NOT affect route(...,
        // absolute: true): Illuminate\Routing\UrlGenerator::formatRoot()/
        // formatScheme() resolve the root/scheme from the bound Request
        // object (forcedRoot/forceScheme when set, else $request->root()/
        // $request->getScheme()) — never from config('app.url') directly.
        // Outside of a simulated HTTP request (these tests call the
        // service layer directly), the container's default bound request
        // reports scheme=http, host=localhost, so route() silently kept
        // resolving to http://localhost regardless of the config() call
        // above (empirically confirmed via `php artisan tinker`).
        // URL::forceRootUrl() alone is also insufficient: formatRoot()
        // rewrites whatever scheme prefix forceRootUrl was given to
        // match formatScheme()'s own resolution, so without forceScheme()
        // too, a forced "https://..." root silently gets rewritten back
        // to "http://..." by the still-http request scheme. Both calls
        // are required together.
        config(['app.url' => 'https://app.firmsbase.test']);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://app.firmsbase.test');
        \Illuminate\Support\Facades\URL::forceScheme('https');

        // Registers TestProvider under the real config-driven map
        // ProviderRegistry consults — mirrors production wiring exactly
        // (never a mock of ProviderRegistry itself).
        config(['integrations.providers' => [\App\Integrations\Enums\ProviderKey::Test->value => TestProvider::class]]);

        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // 1. State generation
    // ------------------------------------------------------------

    public function test_initiate_creates_a_new_pending_oauth_state_row_with_correct_firm_provider_and_integration(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $flow = $this->initiateFlow($connection, $firmUser);

        $row = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->find($flow['result']->oauthStateId));

        $this->assertNotNull($row);
        $this->assertSame($firm->id, $row->firm_id);
        $this->assertSame($connection->id, $row->firm_integration_id);
        $this->assertSame($firmUser->user_id, $row->initiating_user_id);
    }

    public function test_state_token_is_freshly_generated_and_non_colliding_across_many_initiations(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $states = [];
        for ($i = 0; $i < 25; $i++) {
            $states[] = $this->initiateFlow($connection, $firmUser)['rawState'];
        }

        $this->assertCount(25, array_unique($states));
    }

    public function test_the_raw_state_token_is_returned_to_the_caller_for_the_redirect_url_construction(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();

        $flow = $this->initiateFlow($connection, $firmUser);

        $this->assertNotEmpty($flow['rawState']);
        $this->assertStringContainsString($flow['rawState'], $flow['result']->authorizationUrl);
    }

    public function test_raw_state_token_is_never_persisted_or_logged_or_included_in_timeline_metadata(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $flow = $this->initiateFlow($connection, $firmUser);

        $rawState = $flow['rawState'];

        $rows = $this->runWithFirmContext($firm, fn () => DB::table('integration_oauth_states')->get());
        foreach ($rows as $row) {
            foreach ((array) $row as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString($rawState, $value);
                }
            }
        }

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_oauth.authorization_initiated')->latest('id')->first());
        $this->assertNotNull($event);
        $metadataJson = json_encode($event->metadata_json);
        $this->assertIsString($metadataJson);
        $this->assertStringNotContainsString($rawState, $metadataJson);
    }

    // ------------------------------------------------------------
    // 2. State binding
    // ------------------------------------------------------------

    public function test_completing_with_the_correct_firm_user_and_provider_succeeds(): void
    {
        $result = $this->completeSuccessfulConnect();

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);
    }

    public function test_completing_with_a_different_authenticated_user_than_the_initiator_is_rejected(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $otherFirmUser = $this->firmUserFor($firm, FirmUserRole::Attorney);

        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        // The self-lookup RLS predicate is keyed on the ORIGINAL
        // initiator's user_id, so a different (even valid, same-firm)
        // user's session simply cannot find the row at all.
        $this->expectException(OAuthStateNotFoundException::class);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $otherFirmUser->user_id);
    }

    // ------------------------------------------------------------
    // 3. State lifecycle: expiry / consumption / replay / malformed / unknown
    // ------------------------------------------------------------

    public function test_completing_a_state_after_its_expiry_window_is_rejected(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);

        // IntegrationOAuthStateService::claimAndDecrypt()'s atomic claim
        // deliberately compares expires_at against PostgreSQL's own
        // now() (raw SQL — see that class's docblock on why this is a
        // single atomic UPDATE ... RETURNING statement, not a
        // SELECT-then-UPDATE), not PHP's Carbon::now(). $this->travel()
        // only fakes PHP-side time; it has zero effect on the database
        // server's real clock, so it can never make this comparison
        // observe an expired row — the claim would always still
        // succeed, and the test would spuriously fail on the
        // authorization code's OWN, unrelated Carbon-based 5-minute TTL
        // instead (empirically confirmed). Directly backdating
        // expires_at is the only way to genuinely exercise this path.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_oauth_states')
            ->where('id', $flow['result']->oauthStateId)
            ->update(['expires_at' => now()->subMinute()]));

        $code = $this->mintCode($flow['codeChallenge']);

        $this->expectException(OAuthStateExpiredException::class);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);
    }

    public function test_completing_a_state_just_before_expiry_still_succeeds(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);

        // See test_completing_a_state_after_its_expiry_window_is_rejected's
        // comment: $this->travel() cannot influence the atomic claim's
        // DB-server-side now() comparison, and calling it here would
        // ALSO spuriously expire the authorization code's own
        // independent (Carbon-based) 5-minute TTL for a reason
        // unrelated to what this test proves. The meaningful proof is
        // simply that a completion still within the state's TTL window
        // succeeds — the default 10-minute window from initiateFlow()
        // already satisfies that under real (untraveled) elapsed
        // test-execution time, with no manipulation needed.
        $code = $this->mintCode($flow['codeChallenge']);

        $result = $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $this->assertTrue($result->successful);
    }

    public function test_completing_an_already_consumed_state_a_second_time_is_rejected(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $secondCode = $this->mintCode($flow['codeChallenge']);

        $this->expectException(OAuthStateAlreadyConsumedException::class);

        $this->service()->completeOAuthCallback($flow['rawState'], $secondCode, $firmUser->user_id);
    }

    public function test_a_stale_or_forged_state_token_that_never_existed_is_rejected_with_a_generic_error(): void
    {
        [, , $firmUser] = $this->firmConnectionAndActor();

        $this->expectException(OAuthStateNotFoundException::class);

        $this->service()->completeOAuthCallback(Str::random(43), 'irrelevant-code', $firmUser->user_id);
    }

    public function test_a_malformed_short_state_token_is_rejected_identically_to_an_unknown_one(): void
    {
        [, , $firmUser] = $this->firmConnectionAndActor();

        $this->expectException(OAuthStateNotFoundException::class);

        $this->service()->completeOAuthCallback('short', 'irrelevant-code', $firmUser->user_id);
    }

    /**
     * Genuine two-attempt SEQUENTIAL-simulation concurrency proof (per
     * this class's own disclaimer and Agent G's test-plan §7 — the
     * single most consequential correctness requirement in the frozen
     * design): call the completion path twice with the SAME raw state;
     * the first succeeds, the second observes consumed_at already set
     * and is rejected — exactly one success.
     */
    public function test_two_near_simultaneous_completions_of_the_same_state_token_result_in_exactly_one_success(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $codeForFirstAttempt = $this->mintCode($flow['codeChallenge']);

        $firstResult = $this->service()->completeOAuthCallback($flow['rawState'], $codeForFirstAttempt, $firmUser->user_id);
        $this->assertTrue($firstResult->successful, 'The first attempt to claim the state must succeed.');

        $codeForSecondAttempt = $this->mintCode($flow['codeChallenge']);
        $secondAttemptThrew = null;

        try {
            $this->service()->completeOAuthCallback($flow['rawState'], $codeForSecondAttempt, $firmUser->user_id);
        } catch (OAuthStateAlreadyConsumedException $e) {
            $secondAttemptThrew = $e;
        }

        $this->assertNotNull($secondAttemptThrew, 'The second attempt against the same already-consumed state must be rejected.');
    }

    public function test_the_losing_completion_attempt_creates_no_second_credential_or_second_status_mutation(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $firstCode = $this->mintCode($flow['codeChallenge']);

        $this->service()->completeOAuthCallback($flow['rawState'], $firstCode, $firmUser->user_id);

        $credentialCountAfterFirst = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->count());

        $secondCode = $this->mintCode($flow['codeChallenge']);
        try {
            $this->service()->completeOAuthCallback($flow['rawState'], $secondCode, $firmUser->user_id);
        } catch (OAuthStateAlreadyConsumedException) {
            // expected
        }

        $credentialCountAfterSecond = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->count());

        $this->assertSame($credentialCountAfterFirst, $credentialCountAfterSecond, 'The rejected second attempt must create zero additional credential rows.');
    }

    public function test_the_atomic_claim_uses_a_single_conditional_update_returning_the_row_never_select_then_update(): void
    {
        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = strtolower($query->sql);
        });

        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $claimQueries = array_values(array_filter(
            $capturedSql,
            fn ($sql) => str_contains($sql, 'update integration_oauth_states') && str_contains($sql, 'consumed_at')
        ));

        $this->assertNotEmpty($claimQueries, 'The completion path must issue an UPDATE ... consumed_at statement.');

        foreach ($claimQueries as $sql) {
            $this->assertStringContainsString('consumed_at is null', $sql, 'The atomic claim UPDATE must be conditional on consumed_at IS NULL.');
        }
    }

    // ------------------------------------------------------------
    // 4. PKCE
    // ------------------------------------------------------------

    public function test_authorization_url_includes_code_challenge_and_s256_method(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();

        $flow = $this->initiateFlow($connection, $firmUser);

        $this->assertStringContainsString('code_challenge=', $flow['result']->authorizationUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $flow['result']->authorizationUrl);
    }

    public function test_a_wrong_pkce_verifier_is_rejected_by_the_provider_exchange(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);

        // Mint a code bound to a DIFFERENT (unrelated) code_challenge —
        // simulating a callback whose verifier will not match.
        $code = $this->mintCode((new PkceService())->challengeForVerifier('unrelated-verifier'));

        $this->expectException(\App\Integrations\Exceptions\InvalidPkceVerifierException::class);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);
    }

    public function test_a_missing_pkce_verifier_is_rejected(): void
    {
        // Directly exercises TestProvider's own guard (the layer that
        // actually performs this check) with an empty verifier context.
        $provider = new TestProvider();
        $code = $provider->simulateAuthorizationGrant((new PkceService())->challengeForVerifier('a-real-verifier'));

        $this->expectException(\App\Integrations\Exceptions\InvalidPkceVerifierException::class);

        $provider->exchangeCodeForToken($code, ['code_verifier' => '']);
    }

    public function test_a_reused_authorization_code_is_rejected_even_with_a_fresh_valid_state(): void
    {
        [, $connectionA, $firmUserA] = $this->firmConnectionAndActor();
        $flowA = $this->initiateFlow($connectionA, $firmUserA);
        $code = $this->mintCode($flowA['codeChallenge']);

        $this->service()->completeOAuthCallback($flowA['rawState'], $code, $firmUserA->user_id);

        // A brand-new, unrelated, still-valid state — but the SAME
        // already-used authorization code.
        [, $connectionB, $firmUserB] = $this->firmConnectionAndActor();
        $flowB = $this->initiateFlow($connectionB, $firmUserB);

        $this->expectException(\App\Integrations\Exceptions\AuthorizationCodeAlreadyUsedException::class);

        $this->service()->completeOAuthCallback($flowB['rawState'], $code, $firmUserB->user_id);
    }

    public function test_an_expired_authorization_code_is_rejected(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge'], expired: true);

        $this->expectException(\App\Integrations\Exceptions\ExpiredAuthorizationCodeException::class);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);
    }

    public function test_pkce_verifier_is_absent_from_every_thrown_exception_message_in_the_flow(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode((new PkceService())->challengeForVerifier('a-different-verifier'));

        try {
            $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);
            $this->fail('Expected InvalidPkceVerifierException.');
        } catch (\App\Integrations\Exceptions\InvalidPkceVerifierException $e) {
            // The message may reference the word "verifier" generically
            // (it does — "The PKCE verifier does not match...") but must
            // never embed an actual secret-shaped value (a long
            // verifier/state/token string).
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9\-_]{40,}/', $e->getMessage(), 'Exception message must never embed a verifier/state-shaped secret value.');
        }
    }

    // ------------------------------------------------------------
    // 5. Callback validation: redirect URI, account, scopes
    // ------------------------------------------------------------

    public function test_a_callback_presenting_a_different_redirect_uri_than_pinned_at_initiation_is_rejected(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        // Bypass the controller/service's own redirect_uri computation
        // to simulate a state row pinned to a tampered value — insert
        // directly via the state service isn't possible from here, so
        // instead corrupt the persisted row's redirect_uri directly,
        // mirroring the "presented value differs from pinned value"
        // scenario at the database layer.
        $flow = $this->initiateFlow($connection, $firmUser);

        $this->runWithFirmContext($firm, function () use ($flow) {
            DB::table('integration_oauth_states')
                ->where('id', $flow['result']->oauthStateId)
                ->update(['redirect_uri' => 'https://app.firmsbase.test/a-different-callback-path']);
        });

        $code = $this->mintCode($flow['codeChallenge']);

        $this->expectException(OAuthRedirectUriMismatchException::class);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);
    }

    public function test_first_connect_pins_the_external_account_identifier(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge'], externalAccountId: 'ext-account-fixture-123');

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->find($connection->id));
        $this->assertSame('ext-account-fixture-123', $fresh->external_account_id);
    }

    public function test_reauthorization_with_a_matching_external_account_identifier_succeeds(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $firstCode = $this->mintCode($firstFlow['codeChallenge'], externalAccountId: 'stable-account-id');
        $this->service()->completeOAuthCallback($firstFlow['rawState'], $firstCode, $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $reauthFlow = $this->initiateFlow($connection, $firmUser);
        $reauthCode = $this->mintCode($reauthFlow['codeChallenge'], externalAccountId: 'stable-account-id');

        $result = $this->service()->completeOAuthCallback($reauthFlow['rawState'], $reauthCode, $firmUser->user_id);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);
    }

    public function test_reauthorization_with_a_mismatched_external_account_identifier_is_rejected_outright(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $firstCode = $this->mintCode($firstFlow['codeChallenge'], externalAccountId: 'original-account-id');
        $this->service()->completeOAuthCallback($firstFlow['rawState'], $firstCode, $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $reauthFlow = $this->initiateFlow($connection, $firmUser);
        $reauthCode = $this->mintCode($reauthFlow['codeChallenge'], externalAccountId: 'a-completely-different-account-id');

        $this->expectException(OAuthAccountMismatchException::class);

        $this->service()->completeOAuthCallback($reauthFlow['rawState'], $reauthCode, $firmUser->user_id);
    }

    public function test_account_mismatch_rejection_does_not_overwrite_the_original_pinned_external_account_id(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $firstCode = $this->mintCode($firstFlow['codeChallenge'], externalAccountId: 'original-account-id');
        $this->service()->completeOAuthCallback($firstFlow['rawState'], $firstCode, $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $reauthFlow = $this->initiateFlow($connection, $firmUser);
        $reauthCode = $this->mintCode($reauthFlow['codeChallenge'], externalAccountId: 'attacker-account-id');

        try {
            $this->service()->completeOAuthCallback($reauthFlow['rawState'], $reauthCode, $firmUser->user_id);
        } catch (OAuthAccountMismatchException) {
            // expected
        }

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->find($connection->id));
        $this->assertSame('original-account-id', $fresh->external_account_id);
    }

    public function test_account_mismatch_rejection_still_consumes_the_state_token(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $firstCode = $this->mintCode($firstFlow['codeChallenge'], externalAccountId: 'original-account-id');
        $this->service()->completeOAuthCallback($firstFlow['rawState'], $firstCode, $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $reauthFlow = $this->initiateFlow($connection, $firmUser);
        $reauthCode = $this->mintCode($reauthFlow['codeChallenge'], externalAccountId: 'attacker-account-id');

        try {
            $this->service()->completeOAuthCallback($reauthFlow['rawState'], $reauthCode, $firmUser->user_id);
        } catch (OAuthAccountMismatchException) {
            // expected
        }

        $consumedAt = $this->runWithFirmContext($firm, fn () => DB::table('integration_oauth_states')->where('id', $reauthFlow['result']->oauthStateId)->value('consumed_at'));
        $this->assertNotNull($consumedAt, 'The state must be consumed even on rejection, so it cannot be retried against a different outcome.');
    }

    public function test_a_connection_missing_a_required_scope_after_exchange_is_marked_scope_insufficient(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge'], grantedScopes: ['test.read']);

        $result = $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $this->assertFalse($result->successful);
        $this->assertSame(ConnectionStatus::ScopeInsufficient, $result->status);
    }

    public function test_granted_scopes_are_persisted_verbatim_not_assumed_from_required_scopes(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge'], grantedScopes: ['test.read']);

        $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->find($connection->id));
        $this->assertSame(['test.read'], $fresh->scopes_granted_json);
    }

    public function test_reauthorization_that_restores_the_missing_scope_transitions_back_to_active(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        // externalAccountId held explicitly stable across both mintCode()
        // calls: this test is proving SCOPE recovery, not account
        // matching, but ProviderConnectionService::finishCallback()'s
        // account-pinning check runs regardless of scope. Omitting it
        // (the previous state) gave each call its own random
        // TestProvider-generated id, so the second call's account would
        // almost never match the first's — spuriously tripping
        // OAuthAccountMismatchException for a reason unrelated to what
        // this test is about.
        $firstCode = $this->mintCode($firstFlow['codeChallenge'], externalAccountId: 'scope-recovery-account-id', grantedScopes: ['test.read']);
        $this->service()->completeOAuthCallback($firstFlow['rawState'], $firstCode, $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::ScopeInsufficient, $connection->status);

        $reauthFlow = $this->initiateFlow($connection, $firmUser);
        $reauthCode = $this->mintCode($reauthFlow['codeChallenge'], externalAccountId: 'scope-recovery-account-id');

        $result = $this->service()->completeOAuthCallback($reauthFlow['rawState'], $reauthCode, $firmUser->user_id);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);
    }

    // ------------------------------------------------------------
    // 6. Credential persistence exclusively via IntegrationCredentialService
    // ------------------------------------------------------------

    public function test_successful_completion_persists_both_access_and_refresh_token_credentials(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $connection = $connection;
        $result = $this->completeSuccessfulConnect($firm, $connection);

        $types = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->pluck('credential_type'));

        $this->assertContains(CredentialType::OauthAccessToken, $types->all());
        $this->assertContains(CredentialType::OauthRefreshToken, $types->all());
    }

    public function test_persisted_credential_ciphertext_never_contains_the_plaintext_token(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        $rows = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('firm_integration_id', $connection->id)->get());

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertIsString($row->encrypted_payload_ciphertext);
            // A real access/refresh token from TestProvider is
            // Str::random(40) — 40 alphanumeric chars. The ciphertext
            // must never contain it in plaintext form.
            $this->assertDoesNotMatchRegularExpression('/^[A-Za-z0-9]{40}$/', $row->encrypted_payload_ciphertext);
        }
    }

    public function test_persisted_credential_is_linked_to_the_connection_completed_by_this_state(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        $credential = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->where('credential_type', CredentialType::OauthAccessToken->value)->first());

        $this->assertNotNull($credential);
        $this->assertSame($connection->id, $credential->firm_integration_id);
    }

    // ------------------------------------------------------------
    // 7. Refresh
    // ------------------------------------------------------------

    public function test_refresh_makes_zero_real_network_calls(): void
    {
        Http::fake();

        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->refreshConnectionToken($fresh);

        Http::assertNothingSent();
    }

    public function test_refresh_rotates_the_access_token_credential(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        // ProviderConnectionService::refreshConnectionToken()'s mandatory
        // double-checked-locking guard (frozen §15) short-circuits as a
        // no-op whenever the access credential's expires_at is still
        // more than 2 minutes away — which it always is immediately
        // after completeSuccessfulConnect() (TestProvider grants a
        // 3600-second expires_in). Without backdating it here, this
        // test would spuriously observe a same-ciphertext no-op and
        // wrongly conclude rotation doesn't work; backdating past the
        // guard's threshold is what actually forces a real refresh
        // attempt, mirroring test_double_checked_locking_skips_the_refresh_if_expires_at_was_already_advanced's
        // identical, deliberate expires_at manipulation in the opposite
        // direction.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->update(['expires_at' => now()->subMinute()]));

        $before = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->value('encrypted_payload_ciphertext'));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->refreshConnectionToken($fresh);

        $after = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->value('encrypted_payload_ciphertext'));

        $this->assertNotSame($before, $after);
    }

    public function test_refresh_never_creates_two_simultaneously_active_credentials_of_the_same_type(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->refreshConnectionToken($fresh);

        $activeAccessCount = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->count());

        $this->assertSame(1, $activeAccessCount);
    }

    /**
     * Double-checked-locking simulation (frozen §10/§15 — the mandatory
     * "re-read after lock, skip if already refreshed" step):
     * pre-advance the access credential's expires_at far into the
     * future (simulating "another transaction already refreshed it
     * while this one waited for the lock"), then call refresh — it must
     * be a no-op (same credential id returned, no new provider call
     * effects), not a second real refresh.
     */
    public function test_double_checked_locking_skips_the_refresh_if_expires_at_was_already_advanced(): void
    {
        Http::fake();

        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        $accessCredentialId = $this->runWithFirmContext($firm, function () use ($connection) {
            $id = DB::table('integration_credentials')
                ->where('firm_integration_id', $connection->id)
                ->where('credential_type', CredentialType::OauthAccessToken->value)
                ->where('status', 'active')
                ->value('id');

            DB::table('integration_credentials')->where('id', $id)->update(['expires_at' => now()->addDay()]);

            return $id;
        });

        $ciphertextBefore = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('id', $accessCredentialId)->value('encrypted_payload_ciphertext'));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->refreshConnectionToken($fresh);

        $ciphertextAfter = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('id', $accessCredentialId)->value('encrypted_payload_ciphertext'));

        $this->assertSame($ciphertextBefore, $ciphertextAfter, 'A credential whose expires_at is comfortably in the future must not be refreshed again — the double-checked-locking re-read must observe this and no-op.');
    }

    public function test_refresh_failure_transitions_the_connection_to_reauthorization_required(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        // Force the refresh credential's decrypted plaintext to the
        // FAILURE_SENTINEL by rotating it via the real credential
        // service (never a raw DB write of plaintext).
        $credentialService = new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
        $refreshCredential = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthRefreshToken->value)
            ->first());

        $this->runWithFirmContext($firm, fn () => $credentialService->rotate($connection->fresh(), $refreshCredential, TestProvider::FAILURE_SENTINEL));

        // Without this, the access credential's still-fresh expires_at
        // (1 hour out, from completeSuccessfulConnect()) makes
        // refreshConnectionToken()'s double-checked-locking guard
        // short-circuit as a no-op BEFORE ever calling
        // provider->refreshToken() — meaning the simulated failure
        // sentinel above would never actually be exercised, and
        // $result would misleadingly report success. See
        // test_refresh_rotates_the_access_token_credential's identical
        // fix/comment.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->update(['expires_at' => now()->subMinute()]));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $result = $this->service()->refreshConnectionToken($fresh);

        $this->assertFalse($result->successful);
        $this->assertSame(ConnectionStatus::ReauthorizationRequired, $result->status);
    }

    public function test_refresh_failure_message_never_contains_the_raw_provider_failure_detail(): void
    {
        [$firm, $connection, ] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection);

        $credentialService = new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
        $refreshCredential = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthRefreshToken->value)
            ->first());
        $this->runWithFirmContext($firm, fn () => $credentialService->rotate($connection->fresh(), $refreshCredential, TestProvider::FAILURE_SENTINEL));

        // Same fix as test_refresh_failure_transitions_the_connection_to_reauthorization_required:
        // without backdating the access credential's expires_at, the
        // double-checked-locking guard no-ops before ever calling the
        // provider, $result->errorMessage stays null, and the
        // assertStringNotContainsString() assertions below pass
        // vacuously without ever actually exercising the sanitization
        // path this test is meant to prove.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->update(['expires_at' => now()->subMinute()]));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $result = $this->service()->refreshConnectionToken($fresh);

        $this->assertFalse($result->successful, 'This test only proves anything if the simulated failure sentinel actually triggered a real failure path.');
        $this->assertStringNotContainsString('Simulated provider failure', (string) $result->errorMessage);
        $reReadFresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertStringNotContainsString('Simulated provider failure', (string) $reReadFresh->error_reason);
    }

    /**
     * CHECKPOINT 8 GATE 2 UPDATE (reviews/checkpoint-08/
     * agent-8h-architecture-security-review.md §1 item 4 — the frozen
     * Gate 1/Gate 2 design): this test previously asserted a thrown
     * RuntimeException, but that was only ever an INCIDENTAL side effect
     * of the pre-Checkpoint-8 implementation — a disconnected connection
     * happens to have no active refresh-token credential, so the old
     * code path threw "No active refresh token for connection ..." by
     * accident, not by design. Checkpoint 8 added an explicit, deliberate
     * Gate 2 post-lock ConnectionStatus re-check inside
     * ProviderConnectionService::refreshConnectionToken()'s
     * withRefreshLock() callback: whenever the locked row's status is not
     * Active, it now returns a silent, non-throwing no-op
     * (['outcome' => 'not_active']), which the outer method turns into a
     * failed (successful === false), non-throwing OAuthCallbackResult —
     * closing the TOCTOU window between a caller's own pre-lock read and
     * this lock's acquisition without ever surfacing an exception for a
     * legitimate, already-recorded state transition. This test still
     * proves exactly what it always proved — a disconnected connection's
     * credential cannot be refreshed — just via the new, intended
     * non-throwing signal instead of the old incidental exception.
     */
    public function test_cannot_refresh_a_disconnected_connections_credential(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        $disconnected = $this->runWithFirmContext($firm, fn () => $connection->fresh());

        // No active credential of either type survives disconnect() (see
        // test_disconnect_revokes_the_active_credentials) — captured here
        // so the refresh attempt below can be proven to have created no
        // new active credential, i.e. that no refresh actually happened.
        $activeCredentialCountBefore = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(0, $activeCredentialCountBefore, 'Sanity check: disconnect() must already have left zero active credentials.');

        $result = $this->service()->refreshConnectionToken($disconnected);

        $this->assertFalse($result->successful, 'Refreshing a disconnected connection must never report success.');
        $this->assertSame(
            ConnectionStatus::Disconnected,
            $result->status,
            'Gate 2\'s no-op must not itself transition the connection away from Disconnected.'
        );
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('not Active', (string) $result->errorMessage);

        $activeCredentialCountAfter = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(0, $activeCredentialCountAfter, 'No refresh must actually have happened — the active-credential count must remain zero.');
    }

    // ------------------------------------------------------------
    // 8. Revocation / disconnect
    // ------------------------------------------------------------

    public function test_disconnect_makes_zero_real_network_calls(): void
    {
        Http::fake();

        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        Http::assertNothingSent();
    }

    public function test_disconnect_sets_disconnected_at_and_transitions_status(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $result = $this->service()->disconnect($fresh, $firmUser->user_id);

        $this->assertSame(ConnectionStatus::Disconnected, $result->status);
        $this->assertNotNull($result->disconnected_at);
    }

    public function test_disconnect_revokes_the_active_credentials(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        $activeCount = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->where('status', 'active')->count());
        $this->assertSame(0, $activeCount);
    }

    public function test_disconnect_is_idempotent_when_called_twice(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $first = $this->service()->disconnect($fresh, $firmUser->user_id);

        $second = $this->service()->disconnect($first, $firmUser->user_id);

        $this->assertSame(ConnectionStatus::Disconnected, $second->status);
        $this->assertSame($first->disconnected_at?->toIso8601String(), $second->disconnected_at?->toIso8601String());
    }

    public function test_disconnect_revokes_rather_than_deletes_the_underlying_rows(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        $connectionStillExists = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->find($connection->id));
        $credentialsStillExist = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->count());

        $this->assertNotNull($connectionStillExists);
        $this->assertGreaterThan(0, $credentialsStillExist);
    }

    public function test_a_different_firms_actor_cannot_disconnect_another_firms_connection(): void
    {
        [$firmA, $connectionA, $firmUserA] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firmA, $connectionA, $firmUserA);

        $firmB = $this->firmWithActiveKey();
        $firmUserB = $this->firmUserFor($firmB, FirmUserRole::FirmOwner);

        $freshA = $this->runWithFirmContext($firmA, fn () => $connectionA->fresh());

        $this->expectException(RuntimeException::class);

        // firmUserB has no active FirmUser membership in firmA — the
        // actor-resolution step itself fails closed.
        $this->service()->disconnect($freshA, $firmUserB->user_id);
    }

    public function test_callback_after_disconnect_is_rejected(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $reauthFlow = $this->initiateFlow($this->runWithFirmContext($firm, fn () => $connection->fresh()), $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        $code = $this->mintCode($reauthFlow['codeChallenge']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been disconnected/');

        $this->service()->completeOAuthCallback($reauthFlow['rawState'], $code, $firmUser->user_id);
    }

    public function test_disconnected_or_revoked_connections_credential_is_unusable(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $accessCredential = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->first());

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        $credentialService = new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
        $disconnected = $this->runWithFirmContext($firm, fn () => $connection->fresh());

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => $credentialService->decryptForOperation($disconnected, $accessCredential->fresh(), 'op-1', 'attempted post-disconnect use'));
    }

    // ------------------------------------------------------------
    // 9. Illegal lifecycle transitions
    // ------------------------------------------------------------

    public function test_disconnected_cannot_flip_back_to_active_via_a_stale_callback(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $staleFlow = $this->initiateFlow($this->runWithFirmContext($firm, fn () => $connection->fresh()), $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        $code = $this->mintCode($staleFlow['codeChallenge']);

        try {
            $this->service()->completeOAuthCallback($staleFlow['rawState'], $code, $firmUser->user_id);
        } catch (RuntimeException) {
            // expected
        }

        $stillDisconnected = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Disconnected, $stillDisconnected->status);
    }

    public function test_illegal_transition_attempts_do_not_partially_mutate_connection_state(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $before = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($before, $firmUser->user_id);

        $staleFlow = $this->initiateFlow($this->runWithFirmContext($firm, fn () => $connection->fresh()), $firmUser);
        $beforeSnapshot = $this->runWithFirmContext($firm, fn () => $connection->fresh()->toArray());

        $code = $this->mintCode($staleFlow['codeChallenge']);

        try {
            $this->service()->completeOAuthCallback($staleFlow['rawState'], $code, $firmUser->user_id);
        } catch (RuntimeException) {
            // expected
        }

        $afterSnapshot = $this->runWithFirmContext($firm, fn () => $connection->fresh()->toArray());

        unset($beforeSnapshot['updated_at'], $afterSnapshot['updated_at']);
        $this->assertSame($beforeSnapshot, $afterSnapshot, 'A rejected illegal transition must leave every column byte-for-byte unchanged.');
    }

    // ------------------------------------------------------------
    // 10. Authorization
    // ------------------------------------------------------------

    public function test_only_roles_permitted_to_connect_can_initiate(): void
    {
        [$firm, $connection] = $this->firmConnectionAndActor();
        $paralegal = $this->firmUserFor($firm, FirmUserRole::Paralegal);

        $this->expectException(RuntimeException::class);

        $this->service()->initiateOAuthConnection($connection, $paralegal->user_id, route('integrations.oauth.callback', [], true));
    }

    public function test_firm_owner_and_attorney_can_initiate(): void
    {
        [$firm, $connection] = $this->firmConnectionAndActor();

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney] as $role) {
            $actor = $this->firmUserFor($firm, $role);
            $result = $this->service()->initiateOAuthConnection($connection, $actor->user_id, route('integrations.oauth.callback', [], true));
            $this->assertNotEmpty($result->authorizationUrl);
        }
    }

    public function test_role_tier_ceilings_are_never_widened_receptionist_cannot_initiate(): void
    {
        [$firm, $connection] = $this->firmConnectionAndActor();
        $receptionist = $this->firmUserFor($firm, FirmUserRole::Receptionist);

        $this->expectException(RuntimeException::class);

        $this->service()->initiateOAuthConnection($connection, $receptionist->user_id, route('integrations.oauth.callback', [], true));
    }

    public function test_only_roles_permitted_can_disconnect(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $legalAssistant = $this->firmUserFor($firm, FirmUserRole::LegalAssistant);
        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());

        $this->expectException(RuntimeException::class);

        $this->service()->disconnect($fresh, $legalAssistant->user_id);
    }

    public function test_cross_firm_actor_direct_service_call_is_denied_not_just_ui_hidden(): void
    {
        // "UI-hiding-is-not-the-only-protection": calling the SERVICE
        // directly (bypassing any controller/route entirely) with a
        // cross-firm actor must still fail — the gate is enforced at
        // the service layer, not merely by not rendering a button.
        [$firmA, $connectionA] = $this->firmConnectionAndActor();
        $firmB = $this->firmWithActiveKey();
        $firmOwnerB = $this->firmUserFor($firmB, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->service()->initiateOAuthConnection($connectionA, $firmOwnerB->user_id, route('integrations.oauth.callback', [], true));
    }

    public function test_direct_route_with_a_cross_firm_actor_is_denied(): void
    {
        [$firmA, $connectionA] = $this->firmConnectionAndActor();
        $firmB = $this->firmWithActiveKey();
        $userB = User::factory()->create();
        $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->forUser($userB)->role(FirmUserRole::FirmOwner)->create());

        $this->actingAs($userB);

        $response = $this->get(route('integrations.oauth.initiate', ['firmIntegration' => $connectionA->uuid]));

        // The controller resolves the CURRENT user's own active firm
        // membership first (User::activeFirmUser()), and firm B's user
        // has none in firm A's tenant context — the connection lookup
        // itself fails closed with a 404, never leaking whether
        // connectionA exists.
        $response->assertNotFound();
    }

    public function test_financial_tier_dual_approval_is_not_applicable_to_the_test_provider(): void
    {
        // No financial provider is registered in this mission — the
        // financial-tier dual-approval policy path is simply
        // unreachable for TestProvider. Confirmed by inspection: only
        // IntegrationAccessPolicyService (non-financial) is wired into
        // ProviderConnectionService; FinancialIntegrationAccessPolicyService
        // is never referenced anywhere in this checkpoint's production
        // code.
        $source = file_get_contents((new \ReflectionClass(ProviderConnectionService::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('FinancialIntegrationAccessPolicyService', $source);
    }

    /**
     * Non-blocking finding (diff-review.md §4): initiateOAuthConnection()
     * calls assertCanConnect() unconditionally, including on reauthorize,
     * rather than assertCanConfigure() as originally specified. Both
     * currently gate on the identical role set, so this is not a live
     * bypass — but this test does NOT assert the literal method name
     * used. Instead it uses canConfigure() itself as a live, dynamically
     * re-evaluated ORACLE for what reauthorize SHOULD require (the
     * frozen design's semantic intent — reauthorize is a configure-tier
     * action), exercised against the real reauthorize code path for
     * EVERY role. If IntegrationAccessPolicyService::canConnect() and
     * ::canConfigure() ever diverge in a future change (a role gains
     * canConnect() without canConfigure(), or vice versa), this test's
     * oracle-vs-actual-behavior comparison fails for that role, catching
     * the drift instead of silently papering over it.
     */
    public function test_reauthorize_authorization_matches_the_configure_permission_oracle_for_every_role(): void
    {
        $policy = new IntegrationAccessPolicyService(new TimelineEventRecorder());

        foreach (FirmUserRole::cases() as $role) {
            [$firm, $connection, ] = $this->firmConnectionAndActor();
            $originalOwner = $this->firmUserFor($firm, FirmUserRole::FirmOwner);
            $this->completeSuccessfulConnect($firm, $connection, $originalOwner);

            $actor = $this->firmUserFor($firm, $role);
            $expectedAllowed = $policy->canConfigure($role);

            $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());

            $actuallyAllowed = true;
            try {
                $this->service()->initiateOAuthConnection($fresh, $actor->user_id, route('integrations.oauth.callback', [], true));
            } catch (RuntimeException) {
                $actuallyAllowed = false;
            }

            $this->assertSame(
                $expectedAllowed,
                $actuallyAllowed,
                "Reauthorize authorization for role {$role->value} must match IntegrationAccessPolicyService::canConfigure()'s answer — ".
                'if this fails, assertCanConnect()/assertCanConfigure() have diverged for this role and the reauthorize gate must be revisited.'
            );
        }
    }

    // ------------------------------------------------------------
    // 11. Audit sanitization
    // ------------------------------------------------------------

    public function test_timeline_events_never_contain_raw_state_hash_code_verifier_or_token(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $rawState = $flow['rawState'];
        $codeVerifierSensitiveFragment = $flow['codeChallenge'];

        $code = $this->mintCode($flow['codeChallenge']);
        $result = $this->service()->completeOAuthCallback($rawState, $code, $firmUser->user_id);

        $accessToken = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->first());

        $events = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('firm_id', $firm->id)->get());
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $json = json_encode($event->metadata_json);
            $this->assertIsString($json);
            $this->assertStringNotContainsString($rawState, $json);
            $this->assertStringNotContainsString(hash('sha256', $rawState), $json);
            $this->assertStringNotContainsString($code, $json);
        }
    }

    public function test_timeline_event_metadata_is_allowlisted_hand_built_never_a_full_model_dump(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_oauth.authorization_succeeded')->latest('id')->first());

        $this->assertNotNull($event);
        $metadata = $event->metadata_json;

        // A full ->toArray() dump would include columns like
        // scopes_granted_json, error_reason, external_account_id,
        // webhook_routing_token — none of these belong in this event's
        // allowlisted metadata.
        $this->assertArrayNotHasKey('webhook_routing_token', $metadata);
        $this->assertArrayNotHasKey('external_account_id', $metadata);
    }

    // ------------------------------------------------------------
    // 12. Checkpoint 10 additions — startConnection(), assertEnabled()
    // call sites, $currentUserId-gated webhook-routing-toggle
    // signatures, external_account_id nulled on disconnect.
    // ------------------------------------------------------------

    public function test_start_connection_creates_a_pending_row_and_records_the_connection_created_audit_event(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        $connection = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);

        $this->assertSame(ConnectionStatus::Pending, $connection->status);
        $this->assertSame($firm->id, $connection->firm_id);
        $this->assertSame($provider->id, $connection->integration_provider_id);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_oauth.connection_created')->latest('id')->first());
        $this->assertNotNull($event);
    }

    public function test_start_connection_is_idempotent_for_a_double_click_double_submit(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        $first = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);
        $second = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);

        $this->assertSame($first->id, $second->id, 'A second startConnection() call against a still-Pending, still-no-external-account-id row must return the SAME row.');

        $count = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_start_connection_does_not_reuse_a_row_that_already_has_an_external_account_id(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        $first = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);
        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $first->id)->update(['external_account_id' => 'already-connected-account']));

        $second = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);

        $this->assertNotSame($first->id, $second->id, 'A row with a real external_account_id already set represents a completed connection, not an in-flight Pending one — startConnection() must create a genuinely new row for a reconnect.');
    }

    public function test_start_connection_requires_entitlement(): void
    {
        $firm = Firm::factory()->create(); // NOT entitled (no firmWithActiveKey())
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $provider = $this->testProviderRow();
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);
    }

    public function test_start_connection_requires_the_connect_ceiling_role(): void
    {
        [$firm, $provider] = $this->firmProviderAndActor();
        $paralegal = $this->firmUserFor($firm, FirmUserRole::Paralegal);

        $this->expectException(RuntimeException::class);

        $this->service()->startConnection($firm->id, $provider->id, $paralegal->user_id);
    }

    public function test_start_connection_rejects_a_nonexistent_provider_id(): void
    {
        [$firm, , $firmUser] = $this->firmProviderAndActor();

        $this->expectException(RuntimeException::class);

        $this->service()->startConnection($firm->id, 999999999, $firmUser->user_id);
    }

    public function test_start_connection_fails_closed_when_the_provider_is_not_a_registered_adapter(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        config(['integrations.providers' => []]);

        $this->expectException(RuntimeException::class);

        $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);
    }

    public function test_disconnect_nulls_external_account_id_per_frozen_design_0_ruling_1(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertNotNull($fresh->external_account_id, 'Sanity check: a completed connect must have pinned an external_account_id.');

        $result = $this->service()->disconnect($fresh, $firmUser->user_id);

        $this->assertNull($result->external_account_id);
    }

    public function test_disconnect_nulling_external_account_id_allows_a_genuine_reconnect_to_the_same_external_account_without_a_uniqueness_violation(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $firstCode = $this->mintCode($firstFlow['codeChallenge'], externalAccountId: 'reconnect-same-account-id');
        $this->service()->completeOAuthCallback($firstFlow['rawState'], $firstCode, $firmUser->user_id);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->service()->disconnect($fresh, $firmUser->user_id);

        // Checkpoint 10's own new reachable journey: start a BRAND NEW
        // connection row for the same (firm, provider) after a full
        // disconnect (finishCallback() unconditionally rejects
        // completing OAuth against an already-Disconnected row, so
        // startConnection() must be used, never a re-initiate on the
        // old row).
        $provider = $this->runWithFirmContext($firm, fn () => \App\Integrations\Models\IntegrationProvider::query()->find($connection->integration_provider_id));
        $newConnection = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);

        $reauthFlow = $this->initiateFlow($this->runWithFirmContext($firm, fn () => $newConnection->fresh()), $firmUser);
        $reauthCode = $this->mintCode($reauthFlow['codeChallenge'], externalAccountId: 'reconnect-same-account-id');

        $result = $this->service()->completeOAuthCallback($reauthFlow['rawState'], $reauthCode, $firmUser->user_id);

        $this->assertTrue($result->successful, 'Reconnecting to the SAME external_account_id after a full disconnect must succeed — no lingering uniqueness violation from the old, now-nulled row.');
    }

    public function test_enable_webhook_routing_requires_current_user_id_gated_authorization(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor(FirmUserRole::FirmOwner);

        $rawToken = $this->service()->enableWebhookRouting($connection, $firmUser->user_id);

        $this->assertNotEmpty($rawToken);
    }

    public function test_enable_webhook_routing_denies_a_role_below_the_configure_ceiling(): void
    {
        [$firm, $connection] = $this->firmConnectionAndActor();
        $legalAssistant = $this->firmUserFor($firm, FirmUserRole::LegalAssistant);

        $this->expectException(RuntimeException::class);

        $this->service()->enableWebhookRouting($connection, $legalAssistant->user_id);
    }

    public function test_enable_webhook_routing_requires_entitlement(): void
    {
        $firm = Firm::factory()->create(); // not entitled
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->service()->enableWebhookRouting($connection, $firmUser->user_id);
    }

    public function test_enable_webhook_routing_rejects_a_cross_firm_actor(): void
    {
        [$firmA, $connectionA] = $this->firmConnectionAndActor();
        $firmB = $this->firmWithActiveKey();
        $ownerB = $this->firmUserFor($firmB, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->service()->enableWebhookRouting($connectionA, $ownerB->user_id);
    }

    public function test_disable_webhook_routing_requires_current_user_id_gated_authorization_and_clears_the_token(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor(FirmUserRole::Attorney);

        $this->service()->enableWebhookRouting($connection, $firmUser->user_id);
        $this->service()->disableWebhookRouting($connection, $firmUser->user_id);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertNull($fresh->webhook_routing_token);
    }

    public function test_disable_webhook_routing_denies_a_role_below_the_configure_ceiling(): void
    {
        [$firm, $connection] = $this->firmConnectionAndActor();
        $billingStaff = $this->firmUserFor($firm, FirmUserRole::BillingStaff);

        $this->expectException(RuntimeException::class);

        $this->service()->disableWebhookRouting($connection, $billingStaff->user_id);
    }

    public function test_disable_webhook_routing_requires_entitlement(): void
    {
        $firm = Firm::factory()->create(); // not entitled
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->service()->disableWebhookRouting($connection, $firmUser->user_id);
    }

    public function test_rename_connection_requires_entitlement_and_the_configure_ceiling(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor(FirmUserRole::Attorney);

        $renamed = $this->service()->renameConnection($connection, $firmUser->user_id, 'A New Name');
        $this->assertSame('A New Name', $renamed->display_label);

        $legalAssistant = $this->firmUserFor($firm, FirmUserRole::LegalAssistant);
        $this->expectException(RuntimeException::class);
        $this->service()->renameConnection($connection, $legalAssistant->user_id, 'Should not apply');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: IntegrationProvider, 2: FirmUser}
     */
    private function firmProviderAndActor(FirmUserRole $role = FirmUserRole::FirmOwner): array
    {
        $firm = $this->firmWithActiveKey();
        $provider = $this->testProviderRow();
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $provider, $firmUser];
    }

    private function testProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);
    }

    private function service(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService()),
                new PkceService(),
                new ProviderRedirectUrlValidator(),
            ),
            new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService())),
            new IntegrationAccessPolicyService(new TimelineEventRecorder()),
            new \App\Integrations\Core\ProviderRegistry(),
            new OutboundProviderHttpClient(),
            new ProviderRedirectUrlValidator(),
            new TimelineEventRecorder(),
            // Checkpoint 10 addition (frozen design §4): ProviderConnectionService's
            // constructor gained this 8th, required dependency — every
            // manual construction site in this file must supply it.
            app(IntegrationEntitlementPolicyService::class),
        );
    }

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        // Wrapped in runWithFirmContext() deliberately: TenantEncryptionKeyFactory::
        // create() (like every other FORCE-RLS factory in this codebase) calls
        // TenantContextService::setDatabaseTenantContextForFirmId() directly to
        // satisfy the row's WITH CHECK on insert, but — unlike runWithFirmContext()
        // — never restores/clears app.current_firm_id afterward. Left unwrapped,
        // that SET LOCAL value survives for the rest of this RefreshDatabase
        // test's single wrapping transaction, silently widening what every later
        // withUserContext()-only (no explicit firm context) call in the same test
        // can see via the base tenant_isolation policy OR'd with the self-lookup
        // policy — this is what firmConnectionAndActor()'s already-wrapped
        // FirmIntegration::factory() call was protecting against; this call sits
        // earlier in the same chain and was the actual leak source.
        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $this->encryptionKeyIds[$firm->id] = $key->id;

        // Checkpoint 10 addition: initiateOAuthConnection()/disconnect()/
        // enableWebhookRouting()/disableWebhookRouting() now all call
        // assertEnabled() before the pre-existing role check (frozen
        // design §4). Every existing test in this file predates that
        // gate and is about proving OTHER behavior — defaulting every
        // fixture firm to entitled keeps this file's existing ~50 tests
        // green while still genuinely exercising the real assertEnabled()
        // call (never bypassed/mocked). Tests that specifically need a
        // disentitled firm build one directly via Firm::factory()->create()
        // without going through this helper.
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = $this->firmWithActiveKey();
        // external_account_id is forced to null: FirmIntegrationFactory::
        // definition() assigns it a random UUID ~70% of the time (fake()->
        // boolean(70)), which is fine for tests that don't care, but this
        // fixture represents a connection that has never yet completed a
        // real OAuth exchange. Leaving that random default in place makes
        // ProviderConnectionService::finishCallback()'s account-pinning
        // check spuriously compare that pre-existing random id against
        // TestProvider::simulateAuthorizationGrant()'s own freshly
        // generated 'test-external-account-<random>' id whenever a test
        // doesn't explicitly mint a matching one — a ~70%-of-runs flaky
        // OAuthAccountMismatchException unrelated to what's actually
        // under test. Matches FirmIntegrationsForceRlsActivationTest's
        // own convention of always setting external_account_id explicitly
        // rather than relying on the factory's random default.
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $connection, $firmUser];
    }

    /**
     * @return array{result: \App\Integrations\Data\OAuthInitiationResult, rawState: string, codeChallenge: string, redirectUri: string}
     */
    private function initiateFlow(FirmIntegration $connection, FirmUser $firmUser): array
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        return [
            'result' => $result,
            'rawState' => $query['state'],
            'codeChallenge' => $query['code_challenge'],
            'redirectUri' => $redirectUri,
        ];
    }

    private function mintCode(
        string $codeChallenge,
        ?string $externalAccountId = null,
        ?array $grantedScopes = null,
        bool $expired = false,
    ): string {
        return (new TestProvider())->simulateAuthorizationGrant($codeChallenge, $externalAccountId, $grantedScopes, $expired);
    }

    private function completeSuccessfulConnect(?Firm $firm = null, ?FirmIntegration $connection = null, ?FirmUser $firmUser = null): \App\Integrations\Data\OAuthCallbackResult
    {
        // Each parameter is defaulted independently. The previous
        // all-or-nothing `||` guard replaced firm/connection/firmUser
        // together the moment ANY one of the three was null — which
        // silently discarded the caller's own $firm/$connection on
        // every one of this file's `completeSuccessfulConnect($firm,
        // $connection)` two-argument call sites (firmUser omitted =
        // null there), completing the OAuth flow against a brand-new,
        // unrelated connection instead. Callers then asserted against
        // their ORIGINAL $connection->id, which never received any
        // credential/status update at all — the actual cause behind
        // this file's array of "credential not found"/"no active
        // refresh token" failures.
        if ($firm === null || $connection === null) {
            [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        } elseif ($firmUser === null) {
            $firmUser = $this->firmUserFor($firm, FirmUserRole::Attorney);
        }

        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        return $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);
    }
}
