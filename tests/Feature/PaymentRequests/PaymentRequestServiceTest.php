<?php

namespace Tests\Feature\PaymentRequests;

use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentRequestService::class);
    }

    public function test_create_defaults_to_draft_and_records_a_created_event(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm,
            $client,
            PaymentRequestPurpose::EarnedFee,
            PaymentRequestAmountRule::Fixed,
            $creator,
            requestedAmountCents: 15000,
        );

        $this->assertSame(PaymentRequestStatus::Draft, $paymentRequest->status);
        $this->assertNotNull($paymentRequest->uuid);
        $this->assertSame(
            PaymentRequestEventType::Created,
            $this->runWithFirmContext($firm, fn () => $paymentRequest->events()->first()->event_type),
        );
    }

    public function test_create_rejects_a_client_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirmClient = Client::factory()->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $this->expectException(\RuntimeException::class);

        $this->service->create(
            $firm,
            $otherFirmClient,
            PaymentRequestPurpose::EarnedFee,
            PaymentRequestAmountRule::Fixed,
            $creator,
            requestedAmountCents: 15000,
        );
    }

    public function test_payment_plan_installment_purpose_requires_an_installment_target(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(
            $firm,
            $client,
            PaymentRequestPurpose::PaymentPlanInstallment,
            PaymentRequestAmountRule::Fixed,
            $creator,
            requestedAmountCents: 15000,
        );
    }

    public function test_fixed_amount_rule_requires_a_positive_requested_amount(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(
            $firm,
            $client,
            PaymentRequestPurpose::EarnedFee,
            PaymentRequestAmountRule::Fixed,
            $creator,
            requestedAmountCents: null,
        );
    }

    public function test_up_to_amount_rule_requires_an_invoice_or_installment_target(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(
            $firm,
            $client,
            PaymentRequestPurpose::EarnedFee,
            PaymentRequestAmountRule::UpTo,
            $creator,
        );
    }

    public function test_activate_moves_draft_to_active_and_defaults_expiry(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );

        $activated = $this->service->activate($firm, $paymentRequest, $creator);

        $this->assertSame(PaymentRequestStatus::Active, $activated->status);
        $this->assertNotNull($activated->activated_at);
        $this->assertNotNull($activated->expires_at);
    }

    public function test_activate_rejects_a_non_draft_request(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );
        $this->service->activate($firm, $paymentRequest, $creator);

        $this->expectException(\RuntimeException::class);
        $this->service->activate($firm, $paymentRequest->fresh(), $creator);
    }

    public function test_revoke_requires_a_non_blank_reason(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );

        $this->expectException(\RuntimeException::class);
        $this->service->revoke($firm, $paymentRequest, $creator, '');
    }

    public function test_revoke_blocks_an_already_paid_request(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );
        $this->runWithFirmContext($firm, fn () => $paymentRequest->update(['status' => PaymentRequestStatus::Paid]));

        $this->expectException(\RuntimeException::class);
        $this->service->revoke($firm, $paymentRequest->fresh(), $creator, 'Trying to revoke a paid request');
    }

    public function test_signed_url_produces_a_url_that_validates_against_the_named_route(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );
        $this->service->activate($firm, $paymentRequest->fresh(), $creator);

        $url = $this->service->signedUrl($paymentRequest->fresh());

        $this->assertStringContainsString('/pay/'.$paymentRequest->uuid, $url);
        $request = Request::create($url);
        $this->assertTrue(URL::hasValidSignature($request));
    }

    public function test_resolve_by_uuid_returns_null_for_an_unknown_uuid(): void
    {
        $this->assertNull($this->service->resolveByUuid((string) Str::uuid7()));
    }

    public function test_resolve_by_uuid_finds_the_matching_request(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );

        $resolved = $this->service->resolveByUuid($paymentRequest->uuid);

        $this->assertNotNull($resolved);
        $this->assertSame($paymentRequest->id, $resolved->id);
    }

    public function test_validate_payable_amount_fixed_ignores_the_submitted_amount(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 15000,
        );

        $this->assertSame(15000, $this->service->validatePayableAmount($paymentRequest, 999999));
    }

    public function test_validate_payable_amount_up_to_clamps_to_remaining_invoice_balance(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
        $invoice = Invoice::factory()->forClient($client)->create([
            'status' => InvoiceStatus::Sent,
            'total_cents' => 50000,
            'amount_paid_cents' => 20000,
        ]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::UpTo, $creator,
            invoice: $invoice,
        );

        $this->assertSame(10000, $this->service->validatePayableAmount($paymentRequest, 10000));

        $this->expectException(\RuntimeException::class);
        $this->service->validatePayableAmount($paymentRequest, 30001);
    }

    public function test_validate_payable_amount_custom_allowed_rejects_a_non_positive_amount(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->service->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::CustomAllowed, $creator,
        );

        $this->assertSame(500, $this->service->validatePayableAmount($paymentRequest, 500));

        $this->expectException(\RuntimeException::class);
        $this->service->validatePayableAmount($paymentRequest, 0);
    }
}
