<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformPaymentStatus;
use App\Enums\PlatformRefundStatus;
use App\Enums\PlatformRoleCode;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Filament\Pages\PlatformUsageChargesPage;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Models\BillingAccount;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Models\PlatformPaymentAttempt;
use App\Models\UsageRollup;
use App\Services\PlatformRefundService;
use App\Services\PlatformRoleService;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\PaymentGatewaySimulationPolicyService;
use App\Services\Stripe\StripeGateway;
use App\Services\Stripe\UnavailablePaymentGateway;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * BillingOperationsTruthTest — Billing & Commercial Control Plane pass.
 *
 * Billing operations (invoices, payments, usage, refunds) are where a
 * console can most easily lie: a zero that means "not measured", a
 * paid state with nothing behind it, a refund that over-refunds under
 * concurrency. These tests hold the truthful behaviour in place.
 */
final class BillingOperationsTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function billingAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::BillingAdmin);

        return $admin;
    }

    // ------------------------------------------------------------------
    // Payment gateway: the fact everything else depends on
    // ------------------------------------------------------------------

    public function test_no_production_payment_gateway_is_configured_outside_the_test_suite(): void
    {
        $policy = app(PaymentGatewaySimulationPolicyService::class);

        // The test suite always simulates — that is the documented rule.
        $this->assertTrue($policy->isSimulationEnabled());
        $this->assertInstanceOf(FakeStripeGateway::class, app(StripeGateway::class));

        // Every other implementation of the gateway contract in this
        // codebase either simulates or refuses. If a real, money-moving
        // gateway is ever added, this assertion is the tripwire that
        // says "the console's read-only payment disclosures are now
        // out of date and must be revisited".
        $implementations = [FakeStripeGateway::class, UnavailablePaymentGateway::class];

        foreach ($implementations as $implementation) {
            $this->assertTrue(is_subclass_of($implementation, StripeGateway::class));
        }

        $this->assertCount(
            2,
            $implementations,
            'A third StripeGateway implementation appeared. Re-check every "payment recovery is not operational" '.
            'disclosure in the Billing console before changing this test.',
        );
    }

    public function test_the_non_simulated_gateway_refuses_rather_than_fabricating_a_success(): void
    {
        $gateway = new UnavailablePaymentGateway;

        $this->expectException(PaymentProviderUnavailableException::class);

        $gateway->createRefund('pi_whatever', 100);
    }

    // ------------------------------------------------------------------
    // Refund: over-refund protection under serialization
    // ------------------------------------------------------------------

    private function succeededPayment(int $amountCents): PlatformPayment
    {
        return PlatformPayment::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'status' => PlatformPaymentStatus::Succeeded,
            'amount_cents' => $amountCents,
            'gateway_payment_ref' => 'pi_test_reference',
        ]);
    }

    public function test_a_refund_cannot_exceed_the_remaining_refundable_balance(): void
    {
        $payment = $this->succeededPayment(10000);
        $service = app(PlatformRefundService::class);
        $gateway = new FakeStripeGateway;

        $service->refund($payment, 6000, 'partial', $gateway);

        $this->expectException(RuntimeException::class);

        $service->refund($payment, 5000, 'too much', $gateway);
    }

    public function test_a_second_refund_sees_the_first_ones_committed_total(): void
    {
        $payment = $this->succeededPayment(10000);
        $service = app(PlatformRefundService::class);
        $gateway = new FakeStripeGateway;

        $service->refund($payment, 6000, 'first', $gateway);
        $service->refund($payment, 4000, 'second', $gateway);

        $this->assertSame(10000, (int) $payment->refunds()
            ->where('status', PlatformRefundStatus::Completed->value)
            ->sum('amount_cents'));

        $this->assertSame(
            PlatformPaymentStatus::Refunded,
            $payment->fresh()->status,
            'Fully refunding a payment must move it to Refunded, not leave it Partially Refunded.',
        );
    }

    public function test_a_partial_refund_marks_the_payment_partially_refunded(): void
    {
        $payment = $this->succeededPayment(10000);

        app(PlatformRefundService::class)->refund($payment, 2500, 'goodwill', new FakeStripeGateway);

        $this->assertSame(PlatformPaymentStatus::PartiallyRefunded, $payment->fresh()->status);
    }

    public function test_a_zero_or_negative_refund_amount_is_rejected(): void
    {
        $payment = $this->succeededPayment(10000);
        $service = app(PlatformRefundService::class);

        $this->expectException(InvalidArgumentException::class);

        $service->refund($payment, 0, 'nothing', new FakeStripeGateway);
    }

    public function test_a_negative_refund_amount_is_rejected_before_any_gateway_call(): void
    {
        $payment = $this->succeededPayment(10000);

        try {
            app(PlatformRefundService::class)->refund($payment, -500, 'negative', new FakeStripeGateway);
            $this->fail('A negative refund amount must be rejected.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        $this->assertSame(0, $payment->refunds()->count(), 'No refund evidence may be written for a rejected amount.');
        $this->assertSame(PlatformPaymentStatus::Succeeded, $payment->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Invoice detail truth
    // ------------------------------------------------------------------

    private function invoice(PlatformInvoiceStatus $status, int $totalCents = 15000): PlatformInvoice
    {
        return PlatformInvoice::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'status' => $status,
            'subtotal_cents' => $totalCents,
            'tax_cents' => 0,
            'total_cents' => $totalCents,
        ]);
    }

    public function test_an_unpaid_invoice_states_its_amount_due(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::Open);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('Amount due: 150.00 USD');
    }

    public function test_a_voided_invoice_states_that_nothing_is_collectable(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::Void);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('was voided and is not collectable');
        $response->assertSee('retained as evidence');
    }

    public function test_the_invoice_tax_line_states_that_no_tax_is_calculated(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::Open);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('this platform calculates no tax');
    }

    public function test_the_invoice_does_not_show_a_zero_discount_or_credit_line(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::Open);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('No discount or credit line appears on this invoice, and none is shown as zero');
    }

    public function test_the_invoice_shows_its_collection_attempts_including_failures(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::PastDue);

        PlatformPaymentAttempt::factory()->create([
            'billing_account_id' => $invoice->billing_account_id,
            'platform_invoice_id' => $invoice->id,
            'status' => PlatformPaymentAttemptStatus::Failed,
            'attempt_number' => 1,
            'failure_reason' => 'card_declined',
        ]);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('Collection attempts');
        $response->assertSee('card_declined');
        $response->assertSee('No payment has been recorded against this invoice');
    }

    public function test_the_invoice_states_why_no_external_payment_can_be_recorded(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::Open);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('rules out recording an external payment by hand');
        $response->assertSee('Adding that provenance is a schema change');
    }

    public function test_the_invoice_states_its_immutability_guarantee(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $invoice = $this->invoice(PlatformInvoiceStatus::Paid);

        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();
        $response->assertSee('A later plan price change cannot alter this invoice');
        $response->assertSee('no credit note or amendment mechanism');
    }

    // ------------------------------------------------------------------
    // Usage truth
    // ------------------------------------------------------------------

    public function test_the_usage_page_states_that_no_money_is_recorded_against_usage(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        UsageRollup::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
        ]);

        $response = $this->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('No money is shown on this page, because none is recorded');
        $response->assertSee('nothing here has yet been charged to anyone', escape: false);
    }

    /**
     * The page's prose legitimately NAMES priced/unpriced/invoiced/
     * unallocated while denying that any of them exist here, so a
     * body-text search would flag its own disclosure. What must not
     * exist is a bound column or filter presenting one of them as a
     * state of the data — asserted structurally instead.
     */
    public function test_the_usage_page_never_binds_a_priced_or_invoiced_usage_state(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformUsageChargesPage.php'));

        foreach ([
            'unit_price', 'unit_price_cents', 'price_cents', 'charge_cents', 'amount_cents',
            'is_priced', 'priced_at', 'invoiced_at', 'platform_invoice_id', 'finalized_at',
        ] as $absentColumn) {
            $this->assertStringNotContainsString(
                "make('".$absentColumn."'",
                $source,
                'usage_rollups has no '.$absentColumn.' column — binding one would fabricate a pricing state.',
            );
        }
    }

    public function test_the_usage_page_denies_rather_than_displays_a_pricing_state(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        UsageRollup::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
        ]);

        $response = $this->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('no priced, unpriced, billable, unbilled, or invoiced');
        $response->assertSee('no such state is shown');
    }

    public function test_the_usage_page_states_the_immutability_and_missing_adjustment_ledger(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('Usage records are immutable and there is no adjustment ledger');
        $response->assertSee('would destroy the evidence of what was actually observed');
    }

    public function test_the_usage_page_separates_platform_usage_from_provider_cost(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('what FirmsVault itself pays an upstream');
        $response->assertSee('never become');
    }

    public function test_the_usage_page_exposes_no_edit_or_delete_affordance(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformUsageChargesPage.php'));

        $this->assertStringNotContainsString('recordActions(', $source);
        $this->assertStringNotContainsString('headerActions(', $source);
        $this->assertStringNotContainsString('DeleteAction', $source);
        $this->assertStringNotContainsString('EditAction', $source);
        $this->assertStringNotContainsString('->delete(', $source);
    }
}
