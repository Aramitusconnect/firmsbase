<?php

namespace Tests\Feature\PaymentRequests;

use App\Enums\FirmUserRole;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payment Link / QR Routing phase — RLS proofs for payment_requests,
 * mirroring the established clients_self_lookup precedent
 * (ClientPortalTwoHopSelfLookupPolicyTest) exactly: FORCE RLS tenant
 * isolation plus the ADDITIONAL, narrow payment_requests_self_lookup
 * policy that lets an unauthenticated public payer read only the one
 * row their own opaque uuid names.
 */
class PaymentRequestRlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_payment_requests_with_no_context_returns_nothing(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        $rows = DB::table('payment_requests')->count();

        $this->assertSame(0, $rows, 'With absolutely no context active, payment_requests must return zero rows.');
    }

    public function test_firm_context_cannot_see_another_firms_payment_requests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => PaymentRequest::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmB, fn () => PaymentRequest::factory()->forFirm($firmB)->create());

        $visibleToA = $this->runWithFirmContext($firmA, fn () => PaymentRequest::query()->count());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => PaymentRequest::query()->count());

        $this->assertSame(1, $visibleToA);
        $this->assertSame(1, $visibleToB);
    }

    public function test_self_lookup_context_alone_can_read_only_that_requests_own_row(): void
    {
        $firm = Firm::factory()->create();
        $requestOne = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());
        $requestTwo = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $visibleUuids = $tenantContext->withPaymentRequestSelfLookupContext(
            $requestOne->uuid,
            fn () => DB::table('payment_requests')->pluck('uuid')->all(),
        );

        $this->assertContains($requestOne->uuid, $visibleUuids);
        $this->assertNotContains($requestTwo->uuid, $visibleUuids, "A payment-request self-lookup session must never reveal another request's row.");
    }

    public function test_self_lookup_with_an_unknown_uuid_reveals_nothing(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        $visibleCount = (new TenantContextService)->withPaymentRequestSelfLookupContext(
            (string) Str::uuid7(),
            fn () => DB::table('payment_requests')->count(),
        );

        $this->assertSame(0, $visibleCount);
    }

    public function test_self_lookup_context_alone_cannot_insert_a_payment_requests_row(): void
    {
        $firm = Firm::factory()->create();
        // Every fixture below is created through runWithFirmContext()
        // specifically because ITS OWN finally-block restore clears the
        // ambient app.current_firm_id session setting afterward — unlike
        // a factory's own bespoke context-hold create() override (see
        // ClientFactory's docblock), which deliberately leaves it set.
        // Without this, a leaked firm-id context from an earlier fixture
        // would satisfy payment_requests' ordinary tenant_isolation
        // policy on its own, and this test would not actually be
        // proving what self-lookup-ALONE can or cannot do.
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $creator = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]));
        $existing = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $tenantContext->withPaymentRequestSelfLookupContext($existing->uuid, function () use ($firm, $client, $creator) {
            DB::table('payment_requests')->insert([
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'purpose' => PaymentRequestPurpose::EarnedFee->value,
                'amount_rule' => 'fixed',
                'requested_amount_cents' => 1000,
                'currency' => 'usd',
                'status' => 'draft',
                'created_by_firm_user_id' => $creator->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_self_lookup_context_alone_cannot_update_a_payment_requests_row(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create(['failure_reason' => null]));

        $tenantContext = new TenantContextService;

        $affected = $tenantContext->withPaymentRequestSelfLookupContext(
            $paymentRequest->uuid,
            fn () => DB::table('payment_requests')->where('id', $paymentRequest->id)->update(['status' => 'paid']),
        );

        $this->assertSame(0, $affected, 'Self-lookup context alone must never be able to write to payment_requests — it is a FOR SELECT-only policy.');

        $reRead = $this->runWithFirmContext($firm, fn () => DB::table('payment_requests')->where('id', $paymentRequest->id)->value('status'));
        $this->assertSame('draft', $reRead);
    }

    public function test_self_lookup_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        (new TenantContextService)->withPaymentRequestSelfLookupContext($paymentRequest->uuid, fn () => 'ok');

        $value = DB::selectOne("select current_setting('app.current_payment_request_uuid', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_payment_request_uuid must be cleared after a successful call.');
    }

    public function test_self_lookup_context_clears_even_after_an_exception(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        try {
            (new TenantContextService)->withPaymentRequestSelfLookupContext($paymentRequest->uuid, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $value = DB::selectOne("select current_setting('app.current_payment_request_uuid', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_payment_request_uuid must be cleared even when the callback throws.');
    }

    public function test_reading_events_alone_grants_no_access_to_the_payment_requests_table(): void
    {
        // payment_request_events carries no self-lookup policy of its
        // own (write-time context is already established by then) — a
        // caller with only firm B's context must never see firm A's
        // events even if it somehow learned a payment_request_id.
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => PaymentRequest::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmA, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firmA->id,
            'payment_request_id' => $requestA->id,
            'event_type' => PaymentRequestEventType::Created,
        ]));

        $visibleToB = $this->runWithFirmContext($firmB, fn () => DB::table('payment_request_events')->where('payment_request_id', $requestA->id)->count());

        $this->assertSame(0, $visibleToB);
    }
}
