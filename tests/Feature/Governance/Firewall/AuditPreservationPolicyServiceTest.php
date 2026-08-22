<?php

namespace Tests\Feature\Governance\Firewall;

use App\Enums\GovernanceRecordScope;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\PdfViewEvent;
use App\Models\TrustLedgerEntry;
use App\Models\WebhookEvent;
use App\Services\AuditPreservationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test: proves Phase 17 did not weaken any existing
 * append-only log model, and that the audit preservation policy
 * declares every required log family (including the client-portal-log
 * gap, approved decision #8) without inventing a 14th table.
 */
class AuditPreservationPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditPreservationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AuditPreservationPolicyService::class);
    }

    public function test_declares_all_required_log_families(): void
    {
        $families = $this->service->protectedLogFamilies();

        foreach (GovernanceRecordScope::cases() as $case) {
            $this->assertArrayHasKey($case->value, $families);
        }
    }

    public function test_client_portal_log_is_flagged_as_a_required_future_gap(): void
    {
        $this->assertFalse($this->service->isLogFamilyRepresented(GovernanceRecordScope::ClientPortalLog));
        $this->assertContains(GovernanceRecordScope::ClientPortalLog, $this->service->requiredFutureLogFamilies());
    }

    public function test_no_fourteenth_phase_17_table_was_invented_for_client_portal_logs(): void
    {
        $this->assertFalse(Schema::hasTable('client_portal_logs'));
    }

    public function test_existing_trust_ledger_append_only_protection_still_throws(): void
    {
        $entry = TrustLedgerEntry::factory()->create();

        $this->expectException(\LogicException::class);
        $entry->update(['amount_cents' => 999]);
    }

    public function test_existing_ai_usage_event_append_only_protection_still_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => AiUsageEvent::factory()->forFirm($firm)->create());

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_existing_webhook_event_append_only_protection_still_throws(): void
    {
        // webhook_events has permanent FORCE ROW LEVEL SECURITY active
        // (Wave 11) and — unlike TrustLedgerEntry/AiUsageEvent's own
        // factories — WebhookEventFactory has no context-hold create()
        // override, so this bare create() needs an explicit wrap.
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => WebhookEvent::factory()->forFirm($firm)->create());

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_existing_pdf_view_event_append_only_protection_still_throws(): void
    {
        $event = PdfViewEvent::factory()->create();

        $this->expectException(\LogicException::class);
        $event->update(['ip_address' => '10.0.0.99']);
    }

    public function test_security_payment_support_document_access_and_platform_billing_logs_are_represented(): void
    {
        $families = $this->service->protectedLogFamilies();

        $this->assertNotNull($families[GovernanceRecordScope::SecurityLog->value]);
        $this->assertNotNull($families[GovernanceRecordScope::PaymentLog->value]);
        $this->assertNotNull($families[GovernanceRecordScope::TrustLog->value]);
        $this->assertNotNull($families[GovernanceRecordScope::DocumentAccessLog->value]);
        $this->assertNotNull($families[GovernanceRecordScope::SupportAccessLog->value]);
        $this->assertNotNull($families[GovernanceRecordScope::PlatformBillingLog->value]);
        $this->assertNotNull($families[GovernanceRecordScope::AiLog->value]);
    }
}
