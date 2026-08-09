<?php

namespace Tests\Feature\PaymentRequests;

use App\Enums\FirmUserRole;
use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Livewire\PaymentRequests\PublicPaymentPage;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\ManualPaymentService;
use App\Services\PaymentRequestCheckoutService;
use App\Services\PaymentRequestService;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\PaymentGatewaySimulationPolicyService;
use App\Services\Stripe\StripeGateway;
use App\Services\Stripe\UnavailablePaymentGateway;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Payment-Channel Safety Hardening pass, items 1/2/3/6/9. Proves
 * FakeStripeGateway can never make staging/production appear to have
 * received real money, and that the public page/checkout orchestrator
 * behave correctly when no live provider is configured.
 */
class PaymentGatewayFailClosedTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function tearDown(): void
    {
        // Every test below that swaps app()->environment() restores it
        // itself, but this is a defensive backstop against a test that
        // fails before reaching its own restore — must never leak
        // 'production'/'local' into a LATER test's 'testing' env.
        app()->instance('env', 'testing');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Item 1 — PaymentGatewaySimulationPolicyService's own contract.
    // -----------------------------------------------------------------

    public function test_testing_is_always_simulated_regardless_of_config(): void
    {
        config(['services.stripe.gateway_simulation_enabled' => false]);

        $this->assertTrue(app(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled());
    }

    public function test_local_requires_explicit_opt_in(): void
    {
        app()->instance('env', 'local');

        try {
            config(['services.stripe.gateway_simulation_enabled' => false]);
            $this->assertFalse(app(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled(), 'local must NOT auto-simulate — an explicit opt-in is required.');

            config(['services.stripe.gateway_simulation_enabled' => true]);
            $this->assertTrue(app(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled());
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_production_never_simulates_even_if_the_flag_is_set(): void
    {
        app()->instance('env', 'production');

        try {
            config(['services.stripe.gateway_simulation_enabled' => true]);
            $this->assertFalse(
                app(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled(),
                'The simulation flag must have no effect outside local — there must be no way to misconfigure production into faking a payment.'
            );
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_staging_never_simulates_even_if_the_flag_is_set(): void
    {
        app()->instance('env', 'staging');

        try {
            config(['services.stripe.gateway_simulation_enabled' => true]);
            $this->assertFalse(app(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled());
        } finally {
            app()->instance('env', 'testing');
        }
    }

    // -----------------------------------------------------------------
    // Item 1 — the actual container binding.
    // -----------------------------------------------------------------

    public function test_the_container_resolves_fakestripegateway_in_testing(): void
    {
        $this->assertInstanceOf(FakeStripeGateway::class, app(StripeGateway::class));
    }

    public function test_the_container_resolves_unavailablepaymentgateway_outside_simulation(): void
    {
        app()->instance('env', 'production');

        try {
            $this->assertInstanceOf(UnavailablePaymentGateway::class, app(StripeGateway::class));
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_unavailablepaymentgateway_never_returns_a_fabricated_success(): void
    {
        $gateway = new UnavailablePaymentGateway;

        $this->expectException(PaymentProviderUnavailableException::class);
        $gateway->createPaymentIntent(5000, 'usd');
    }

    // -----------------------------------------------------------------
    // Items 2/3/6/9 — end-to-end: no provider available.
    // -----------------------------------------------------------------

    private function activeRequest(?PaymentRequestPurpose $purpose = null): array
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, $purpose ?? PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        return [$firm, $paymentRequest->fresh()];
    }

    public function test_checkout_throws_and_creates_nothing_when_no_provider_is_configured(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();

        $checkout = new PaymentRequestCheckoutService(
            app(PaymentRequestService::class),
            app(ManualPaymentService::class),
            app(TrustDepositService::class),
            new UnavailablePaymentGateway,
        );

        try {
            $checkout->submitPayment($paymentRequest, 5000);
            $this->fail('Expected PaymentProviderUnavailableException.');
        } catch (PaymentProviderUnavailableException) {
            // expected
        }

        $reRead = $this->runWithFirmContext($firm, fn () => $paymentRequest->fresh());
        $this->assertSame(PaymentRequestStatus::Active, $reRead->status, 'A provider-unavailable attempt must never move the request out of Active — it remains genuinely payable once a real provider is configured.');
        $this->assertNull($reRead->payment_id);
        $paymentCount = $this->runWithFirmContext($firm, fn () => DB::table('payments')->where('firm_id', $firm->id)->count());
        $this->assertSame(0, $paymentCount, 'No Payment row may exist — no real money was ever confirmed.');
    }

    /**
     * Item 6 — a Trust deposit request must never even reach
     * TrustDepositService::requestDeposit() (i.e. no TrustApprovalEvent
     * at all, pending or otherwise) when no provider is configured —
     * the gateway throws before routeConfirmedPayment() is ever
     * reached.
     */
    public function test_trust_deposit_creates_no_deposit_request_when_no_provider_is_configured(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::TrustDeposit, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 30000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = new PaymentRequestCheckoutService(
            app(PaymentRequestService::class),
            app(ManualPaymentService::class),
            app(TrustDepositService::class),
            new UnavailablePaymentGateway,
        );

        try {
            $checkout->submitPayment($paymentRequest->fresh(), 30000);
            $this->fail('Expected PaymentProviderUnavailableException.');
        } catch (PaymentProviderUnavailableException) {
            // expected
        }

        $depositEventCount = $this->runWithFirmContext($firm, fn () => DB::table('trust_approval_events')->where('trust_ledger_id', $ledger->id)->count());
        $this->assertSame(0, $depositEventCount, 'No TrustApprovalEvent — not even a pending DepositRequested one — may exist without genuine provider confirmation.');

        $balance = $this->runWithFirmContext($firm, fn () => $ledger->fresh()->balance->fresh()->balance_cents);
        $this->assertSame(0, $balance);
    }

    public function test_the_public_page_hides_pay_now_when_no_provider_is_available(): void
    {
        app()->instance('env', 'production');

        try {
            [$firm, $paymentRequest] = $this->activeRequest();

            Livewire::test(PublicPaymentPage::class, ['uuid' => $paymentRequest->uuid])
                ->assertSet('providerAvailable', false)
                ->assertSee('Online payment is not currently available')
                ->assertDontSee('Pay now');
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_the_public_page_still_shows_request_details_when_no_provider_is_available(): void
    {
        app()->instance('env', 'production');

        try {
            [$firm, $paymentRequest] = $this->activeRequest();

            Livewire::test(PublicPaymentPage::class, ['uuid' => $paymentRequest->uuid])
                ->assertSee('Payment for earned legal fees');
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_submitting_when_no_provider_is_available_is_rejected_server_side_even_if_forced(): void
    {
        app()->instance('env', 'production');

        try {
            [$firm, $paymentRequest] = $this->activeRequest();

            Livewire::test(PublicPaymentPage::class, ['uuid' => $paymentRequest->uuid])
                ->set('providerAvailable', true) // simulate a tampered/forged client state
                ->call('submit')
                ->assertSet('resultSucceeded', false)
                ->assertSee('Online payment is not currently available');

            $reRead = $this->runWithFirmContext($firm, fn () => $paymentRequest->fresh());
            $this->assertSame(PaymentRequestStatus::Active, $reRead->status);
        } finally {
            app()->instance('env', 'testing');
        }
    }
}
