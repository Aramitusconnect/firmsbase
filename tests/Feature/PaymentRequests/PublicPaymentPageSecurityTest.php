<?php

namespace Tests\Feature\PaymentRequests;

use App\Enums\FirmUserRole;
use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payment Link / QR Routing phase, master prompt item 16 — public-route
 * security. `/pay/{uuid}` is the first genuinely public, unauthenticated
 * PAGE this codebase has ever had (see routes/web.php's own docblock);
 * every test below proves it cannot be used to disclose or corrupt
 * anything beyond the exact request its own signed URL names.
 */
class PublicPaymentPageSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function activeRequest(): array
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        return [$firm, $paymentRequest->fresh()];
    }

    public function test_a_validly_signed_url_for_an_active_request_renders_successfully(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();
        $url = app(PaymentRequestService::class)->signedUrl($paymentRequest);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Payment for earned legal fees', false);
    }

    public function test_the_bare_route_with_no_signature_at_all_is_rejected(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();

        $response = $this->get('/pay/'.$paymentRequest->uuid);

        $response->assertForbidden();
    }

    public function test_a_tampered_uuid_against_someone_elses_signature_is_rejected(): void
    {
        [$firmA, $requestA] = $this->activeRequest();
        [$firmB, $requestB] = $this->activeRequest();

        $urlForA = app(PaymentRequestService::class)->signedUrl($requestA);
        $tamperedUrl = str_replace($requestA->uuid, $requestB->uuid, $urlForA);

        $response = $this->get($tamperedUrl);

        $response->assertForbidden();
    }

    public function test_an_expired_signature_is_rejected(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();

        $expiredUrl = URL::temporarySignedRoute('public.payment-requests.show', now()->subMinute(), ['uuid' => $paymentRequest->uuid]);

        $response = $this->get($expiredUrl);

        $response->assertForbidden();
    }

    public function test_a_modified_query_parameter_invalidates_the_signature(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();
        $url = app(PaymentRequestService::class)->signedUrl($paymentRequest);

        $response = $this->get($url.'&injected=1');

        $response->assertForbidden();
    }

    public function test_a_genuinely_unknown_but_well_formed_uuid_never_validates(): void
    {
        // A valid signature can only ever be produced for a uuid this
        // app itself generated the link for — there is no way to
        // "guess" a signature, so this doubles as the guessed-identifier
        // proof: any uuid not matched by a real, previously-issued
        // signature 403s identically to a completely made-up one.
        $unknownUuid = (string) Str::uuid7();

        $response = $this->get('/pay/'.$unknownUuid.'?expires=9999999999&signature=deadbeef');

        $response->assertForbidden();
    }

    public function test_a_revoked_requests_link_still_loads_but_reports_not_payable(): void
    {
        // DB-side revocation is independent of the signature's own
        // cryptographic expiry (PaymentRequestService::signedUrl()'s
        // own docblock) — the signed URL keeps validating right up to
        // its own expires_at, but the page must show the request is no
        // longer available rather than accepting a payment.
        [$firm, $paymentRequest] = $this->activeRequest();
        $url = app(PaymentRequestService::class)->signedUrl($paymentRequest);
        $creator = $this->runWithFirmContext($firm, fn () => $paymentRequest->createdBy);

        app(PaymentRequestService::class)->revoke($firm, $paymentRequest, $creator, 'Test revocation');

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('no longer available', false);
        $response->assertDontSee('Pay now', false);
    }

    public function test_a_paid_requests_link_never_re_exposes_a_second_payment_attempt(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();
        $url = app(PaymentRequestService::class)->signedUrl($paymentRequest);

        $this->runWithFirmContext($firm, fn () => $paymentRequest->update(['status' => PaymentRequestStatus::Paid]));

        $response = $this->get($url);

        $response->assertOk();
        $response->assertDontSee('Pay now', false);
    }

    public function test_the_page_never_discloses_the_firms_other_clients_or_matters(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();
        $otherClient = Client::factory()->forFirm($firm)->create(['display_name' => 'Some Other Confidential Client']);
        $url = app(PaymentRequestService::class)->signedUrl($paymentRequest);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertDontSee('Some Other Confidential Client', false);
    }

    public function test_repeated_requests_past_the_throttle_limit_are_rejected(): void
    {
        [$firm, $paymentRequest] = $this->activeRequest();
        $url = app(PaymentRequestService::class)->signedUrl($paymentRequest);

        $lastStatus = null;
        for ($i = 0; $i < 35; $i++) {
            $lastStatus = $this->get($url)->getStatusCode();
        }

        $this->assertSame(429, $lastStatus, 'The public payment page must be rate-limited (throttle:30,1) — 35 requests in a row must eventually be rejected.');
    }
}
