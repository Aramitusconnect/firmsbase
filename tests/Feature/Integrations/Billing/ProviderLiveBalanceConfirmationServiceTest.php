<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Enums\EntitlementSource;
use App\Integrations\Billing\ProviderLiveBalanceConfirmationContext;
use App\Integrations\Billing\ProviderLiveBalanceConfirmationService;
use App\Integrations\Contracts\SupportsBalanceContract;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderInvalidOrExpiredConfirmationTokenException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBalanceSnapshot;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderOperationDefaultPolicy;
use App\Integrations\Models\ProviderRateCardEntry;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ProviderLiveBalanceConfirmationServiceTest — checkpoint4-design-cost-control.md
 * §5.3/§5.4. Proves `prepare()` surfaces every field the product
 * owner's own quoted safeguard requirement names verbatim ("last
 * successful balance retrieval; cached balance age; included allowance
 * remaining; estimated customer charge or overage; cooldown remaining;
 * reason field; confirmation step"), and that `confirm()`'s single-use
 * token genuinely prevents a repeated-click / concurrent-duplicate
 * confirmation (a second `confirm()` call with the same token is
 * rejected because `Cache::pull()` already consumed it).
 */
class ProviderLiveBalanceConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderLiveBalanceConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProviderLiveBalanceConfirmationService::class);
        Cache::flush();
    }

    private function firmWithEntitlement(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    /**
     * `confirm()`'s pipeline writes `provider_billing.call_reserved` via
     * `TimelineEventRecorder::record(..., independentOfAmbientTransaction: true)`
     * (`ProviderBillableCallPipeline`) — a genuinely separate `pgsql_audit`
     * PDO session (see that recorder's own docblock), which can only see
     * a Firm row that is genuinely committed in another database
     * session. `firmWithEntitlement()`'s ordinary
     * `Firm::factory()->create()` is never committed for the whole
     * duration of a `RefreshDatabase`-wrapped test, so any test that
     * reaches `confirm()` must create its Firm this way instead —
     * mirrors `IntegrationAccessPolicyServiceTest::cleanUpDurableFirmAuditTrailAfterRollback()`'s
     * own already-established precedent for the identical problem.
     */
    private function firmWithEntitlementDurableForAudit(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    /**
     * MUST run via beforeApplicationDestroyed(), not an inline
     * try/finally in the test body — see
     * IntegrationAccessPolicyServiceTest's identical helper for the full
     * "why" (a FOR KEY SHARE lock held by the default connection's still-
     * open RefreshDatabase transaction deadlocks an earlier delete).
     */
    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function actor(Firm $firm): FirmUser
    {
        return $this->createWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());
    }

    private function fakeBalanceProvider(array $payload = ['available_cents' => 15000, 'current_cents' => 15500, 'iso_currency_code' => 'usd']): SupportsBalanceContract
    {
        return new class($payload) implements SupportsBalanceContract
        {
            public function __construct(private readonly array $payload) {}

            public function fetchBalance(FirmIntegration $connection, string $accountId, array $context): array
            {
                return $this->payload;
            }
        };
    }

    // ------------------------------------------------------------
    // prepare() — every quoted safeguard field
    // ------------------------------------------------------------

    public function test_prepare_surfaces_no_prior_retrieval_and_a_confirmation_token_on_a_first_ever_check(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);

        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);

        $this->assertInstanceOf(ProviderLiveBalanceConfirmationContext::class, $context);
        $this->assertNull($context->lastSuccessfulRetrievalAt);
        $this->assertNull($context->cachedBalanceAgeSeconds);
        $this->assertSame(0, $context->cooldownRemainingSeconds);
        $this->assertNotEmpty($context->confirmationToken);
        $this->assertGreaterThan(0, $context->confirmationTokenExpiresInSeconds);
    }

    public function test_prepare_surfaces_the_last_successful_retrieval_and_its_age_when_a_snapshot_exists(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'account_id' => 'account-1',
            'available_cents' => 10000,
            'current_cents' => 10500,
            'iso_currency_code' => 'usd',
            'retrieved_at' => now()->subMinutes(10),
        ]));

        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);

        $this->assertNotNull($context->lastSuccessfulRetrievalAt);
        $this->assertNotNull($context->cachedBalanceAgeSeconds);
        $this->assertGreaterThanOrEqual(590, $context->cachedBalanceAgeSeconds);
    }

    public function test_prepare_surfaces_estimated_charge_as_null_when_the_rate_is_unknown_never_fabricating_zero(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);

        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);

        $this->assertNull($context->estimatedCustomerChargeCents);
    }

    public function test_prepare_surfaces_the_resolved_customer_price_when_a_rate_card_row_exists(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        ProviderRateCardEntry::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'balance',
            'billing_operation' => 'get',
            'environment' => 'production',
            'scope_type' => 'platform_default',
            'customer_price_cents' => 42,
            'unit' => 'request',
            'effective_from' => now()->subYear(),
        ]);

        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);

        $this->assertSame(42, $context->estimatedCustomerChargeCents);
    }

    public function test_prepare_surfaces_included_allowance_remaining_and_overage_when_a_soft_limit_is_configured(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'balance',
            'environment' => 'production',
            'soft_limit_quantity' => 3,
        ]);

        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);

        $this->assertSame(3, $context->includedAllowanceRemaining);
        $this->assertFalse($context->isOverage);
        $this->assertFalse($context->reasonRequired);
    }

    public function test_prepare_never_mutates_state_or_creates_a_reservation(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);

        $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);

        $reservationCount = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->count());
        $this->assertSame(0, $reservationCount);
    }

    // ------------------------------------------------------------
    // confirm() — token single-use / repeated-click protection
    // ------------------------------------------------------------

    public function test_confirm_with_a_valid_token_runs_the_full_pipeline_and_persists_a_balance_snapshot(): void
    {
        $firm = $this->firmWithEntitlementDurableForAudit();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);
        $provider = $this->fakeBalanceProvider();

        $result = $this->service->confirm(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor, $provider, $context->confirmationToken);

        $this->assertTrue($result->outcome->certain);
        $this->assertTrue($result->outcome->billable);

        $snapshot = $this->runWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()
            ->where('firm_integration_id', $connection->id)
            ->where('account_id', 'account-1')
            ->first());
        $this->assertNotNull($snapshot);
        $this->assertSame(15000, $snapshot->available_cents);
    }

    public function test_a_second_confirm_with_the_same_token_is_rejected_repeated_click_protection(): void
    {
        $firm = $this->firmWithEntitlementDurableForAudit();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);
        $provider = $this->fakeBalanceProvider();

        // First click consumes the token.
        $this->service->confirm(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor, $provider, $context->confirmationToken);

        // A second click (or a second racing browser tab) with the
        // identical token must fail closed — the token was already
        // atomically pulled from cache by the first call.
        $this->expectException(ProviderInvalidOrExpiredConfirmationTokenException::class);

        $this->service->confirm(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor, $provider, $context->confirmationToken);
    }

    public function test_confirm_with_an_unknown_token_is_rejected(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        $provider = $this->fakeBalanceProvider();

        $this->expectException(ProviderInvalidOrExpiredConfirmationTokenException::class);

        $this->service->confirm(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor, $provider, 'not-a-real-token');
    }

    public function test_confirm_with_a_token_minted_for_a_different_account_is_rejected(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);
        $provider = $this->fakeBalanceProvider();

        $this->expectException(ProviderInvalidOrExpiredConfirmationTokenException::class);

        $this->service->confirm(ProviderKey::Plaid, $connection, $firm, 'account-DIFFERENT', 'production', $actor, $provider, $context->confirmationToken);
    }

    public function test_confirm_after_the_token_ttl_has_elapsed_is_rejected(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $actor = $this->actor($firm);
        $context = $this->service->prepare(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor);
        $provider = $this->fakeBalanceProvider();

        $this->travel($context->confirmationTokenExpiresInSeconds + 5)->seconds();

        $this->expectException(ProviderInvalidOrExpiredConfirmationTokenException::class);

        $this->service->confirm(ProviderKey::Plaid, $connection, $firm, 'account-1', 'production', $actor, $provider, $context->confirmationToken);
    }
}
