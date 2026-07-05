<?php

namespace Tests\Feature\Signature\Readiness;

use App\Services\SignatureEsignLegalReadinessService;
use Tests\TestCase;

class SignatureEsignLegalReadinessServiceTest extends TestCase
{
    private SignatureEsignLegalReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignatureEsignLegalReadinessService();
    }

    public function test_checklist_returns_the_six_required_checks(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(6, $checklist);
        $this->assertArrayHasKey('intent_to_sign_captured', $checklist);
        $this->assertArrayHasKey('consumer_disclosure_and_consent', $checklist);
        $this->assertArrayHasKey('record_retention_capability', $checklist);
        $this->assertArrayHasKey('tamper_evidence', $checklist);
        $this->assertArrayHasKey('signature_record_association', $checklist);
        $this->assertArrayHasKey('jurisdiction_review_completed', $checklist);
    }

    public function test_is_complete_requires_every_check_confirmed(): void
    {
        $this->assertFalse($this->service->isComplete([]));
        $this->assertTrue($this->service->isComplete(array_keys($this->service->checklist())));
    }

    public function test_service_never_concludes_enforceability_itself(): void
    {
        $source = file_get_contents((new \ReflectionClass(SignatureEsignLegalReadinessService::class))->getFileName());

        $this->assertStringNotContainsString('is_enforceable', $source);
        $this->assertStringNotContainsString('isEnforceable', $source);
    }
}
