<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\PlatformInvoiceStatus;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Services\PlatformInvoiceService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformInvoiceService;
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

    // ------------------------------------------------------------
    // Phase 3 FirmsVault Admin Control Center additions — actor +
    // audit plumbing on finalize()/void(). markPaid() is deliberately
    // untouched (see that method's own docblock) — no test added here.
    // ------------------------------------------------------------

    public function test_finalize_and_void_without_an_actor_write_no_audit_events(): void
    {
        $account = BillingAccount::factory()->create();
        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());
        $secondInvoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $this->service->finalize($invoice);
        $this->service->void($secondInvoice);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->whereIn('event_type', ['invoice_finalized', 'invoice_voided'])
                ->count()
        );
        $this->assertSame(0, $count, 'No actor supplied means no audit event and no behavior change from before this addition.');
    }

    public function test_finalize_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $account = BillingAccount::factory()->create();
        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $finalized = $this->service->finalize($invoice, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'invoice_finalized')->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id);
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('platform_billing', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($finalized->id, $metadata['platform_invoice_id']);
        $this->assertSame($account->id, $metadata['billing_account_id']);
        $this->assertSame('open', $metadata['resulting_status']);
        $this->assertEqualsCanonicalizing(
            ['platform_invoice_id', 'billing_account_id', 'resulting_status'],
            array_keys($metadata)
        );
    }

    public function test_void_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $account = BillingAccount::factory()->create();
        $invoice = $this->service->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $voided = $this->service->void($invoice, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'invoice_voided')->first()
        );

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($voided->id, $metadata['platform_invoice_id']);
        $this->assertSame($account->id, $metadata['billing_account_id']);
        $this->assertSame('void', $metadata['resulting_status']);
    }
}
