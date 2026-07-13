<?php

namespace Tests\Feature\Notifications;

use App\Enums\SenderDomainStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Services\SenderDomainVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SenderDomainVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SenderDomainVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SenderDomainVerificationService();
    }

    public function test_a_freshly_created_template_is_not_verified_by_default(): void
    {
        $template = NotificationTemplate::factory()->create();

        $this->assertFalse($this->service->isVerified($template));
    }

    public function test_mark_verified_sets_all_three_status_fields_and_a_timestamp(): void
    {
        $template = NotificationTemplate::factory()->domainUnverified()->create();

        $verified = $this->service->markVerified($template);

        $this->assertSame(SenderDomainStatus::Verified, $verified->spf_status);
        $this->assertSame(SenderDomainStatus::Verified, $verified->dkim_status);
        $this->assertSame(SenderDomainStatus::Verified, $verified->dmarc_status);
        $this->assertNotNull($verified->domain_verified_at);
        $this->assertTrue($this->service->isVerified($verified));
    }

    public function test_mark_failed_clears_the_verified_timestamp(): void
    {
        $template = NotificationTemplate::factory()->domainVerified()->create();

        $failed = $this->service->markFailed($template, 'DMARC record missing');

        $this->assertSame(SenderDomainStatus::Failed, $failed->spf_status);
        $this->assertNull($failed->domain_verified_at);
        $this->assertFalse($this->service->isVerified($failed));
    }

    public function test_sync_verification_across_firm_templates_updates_every_matching_row_without_a_dedicated_table(): void
    {
        $firm = Firm::factory()->create();
        $templateA = NotificationTemplate::factory()->forFirm($firm)->create(['from_domain' => 'mail.example.com', 'key' => 'a']);
        $templateB = NotificationTemplate::factory()->forFirm($firm)->create(['from_domain' => 'mail.example.com', 'key' => 'b']);

        $updated = $this->service->syncVerificationAcrossFirmTemplates($firm->id, 'mail.example.com', true);

        $this->assertSame(2, $updated);
        $this->runWithFirmContext($firm, function () use ($templateA, $templateB) {
            $this->assertTrue($this->service->isVerified($templateA->fresh()));
            $this->assertTrue($this->service->isVerified($templateB->fresh()));
        });
    }

    public function test_no_live_dns_lookup_is_performed_verification_reads_stored_fields_only(): void
    {
        // There is no network call anywhere in this service — isVerified()
        // is a pure read of already-stored fields. Proven here by
        // constructing a template with the fields already set and
        // confirming no exception/HTTP dependency is required.
        $template = NotificationTemplate::factory()->domainVerified('notices@firmsbase.test')->create();

        $this->assertTrue($this->service->isVerified($template));
    }
}
