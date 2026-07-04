<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\PlatformInvoiceStatus;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\Organization;
use App\Services\PlatformInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformInvoiceService();
    }

    public function test_create_draft_invoice(): void
    {
        $account = BillingAccount::factory()->create();

        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(PlatformInvoiceStatus::Draft, $invoice->status);
        $this->assertSame($account->id, $invoice->billing_account_id);
    }

    public function test_add_line_recalculates_invoice_totals(): void
    {
        $account = BillingAccount::factory()->create();
        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $this->service->addLine($invoice, 'Base plan', 1, 19900);
        $this->service->addLine($invoice, 'Extra seat', 2, 1500);

        $this->assertSame(22900, $invoice->fresh()->subtotal_cents);
        $this->assertSame(22900, $invoice->fresh()->total_cents);
    }

    public function test_consolidated_invoice_supports_per_firm_usage_attribution(): void
    {
        $organization = Organization::factory()->create();
        $account = BillingAccount::factory()->create(['organization_id' => $organization->id]);
        $firmA = Firm::factory()->create(['organization_id' => $organization->id, 'billing_account_id' => $account->id]);
        $firmB = Firm::factory()->create(['organization_id' => $organization->id, 'billing_account_id' => $account->id]);
        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $this->service->addLine($invoice, 'AI usage', 1000, 1, $firmA, 'ai_tokens');
        $this->service->addLine($invoice, 'Storage usage', 50, 10, $firmB, 'storage');

        $this->assertDatabaseHas('platform_invoice_lines', ['platform_invoice_id' => $invoice->id, 'firm_id' => $firmA->id, 'usage_metric' => 'ai_tokens']);
        $this->assertDatabaseHas('platform_invoice_lines', ['platform_invoice_id' => $invoice->id, 'firm_id' => $firmB->id, 'usage_metric' => 'storage']);
        $this->assertSame(2, $invoice->lines()->count());
    }

    public function test_finalize_mark_paid_and_void(): void
    {
        $account = BillingAccount::factory()->create();
        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(PlatformInvoiceStatus::Open, $this->service->finalize($invoice)->status);
        $this->assertSame(PlatformInvoiceStatus::Paid, $this->service->markPaid($invoice->fresh())->status);

        $secondInvoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(PlatformInvoiceStatus::Void, $this->service->void($secondInvoice)->status);
    }
}
