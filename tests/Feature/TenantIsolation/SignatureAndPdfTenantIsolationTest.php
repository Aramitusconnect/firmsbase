<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\TenantContextResolver;
use App\Services\TenantSafeSignatureAndPdfPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureAndPdfTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantSafeSignatureAndPdfPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TenantSafeSignatureAndPdfPolicyService();
    }

    public function test_signature_request_belonging_to_a_different_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $request = SignatureRequest::factory()->create(['firm_id' => $firmA->id]);

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertSignatureRequestBelongsToFirm($request, $firmB);
    }

    public function test_signature_request_belonging_to_the_same_firm_passes(): void
    {
        $firm = Firm::factory()->create();
        $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);

        $this->policy->assertSignatureRequestBelongsToFirm($request, $firm);
        $this->addToAssertionCount(1);
    }

    public function test_signature_request_recipient_belonging_to_a_different_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $request = SignatureRequest::factory()->create(['firm_id' => $firmA->id]);
        $recipient = SignatureRequestRecipient::factory()->forRequest($request)->create();

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertSignatureRequestRecipientBelongsToFirm($recipient, $firmB);
    }

    public function test_signature_certificate_belonging_to_a_different_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $certificate = SignatureCertificate::factory()->create(['firm_id' => $firmA->id]);

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertSignatureCertificateBelongsToFirm($certificate, $firmB);
    }

    public function test_signature_request_query_is_narrowed_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        SignatureRequest::factory()->create(['firm_id' => $firmA->id]);
        SignatureRequest::factory()->create(['firm_id' => $firmB->id]);

        (new TenantContextResolver())->activateForFirm($firmA);

        $this->assertSame(1, SignatureRequest::query()->count());

        TenantContextResolver::clear();
    }

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }
}
