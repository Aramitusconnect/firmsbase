<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentScanStatus;
use App\Jobs\ScanDocumentJob;
use App\Models\Document;
use App\Models\Firm;
use App\Services\DocumentSecurityService;
use App\Services\TenantContextService;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ScanDocumentJobTenantContextTest — Mission 1B (Extreme Security
 * Hardening). Regression coverage for a real bug the mission's own RLS
 * adversarial audit found: `documents` is FORCE-RLS'd, but
 * ScanDocumentJob::handle() used to call `Document::query()->find()`
 * with NO tenant context established. Under live FORCE RLS this always
 * returned null — indistinguishable from the legitimate "document was
 * deleted before the scan ran" no-op branch — so malware scanning was
 * silently disabled fleet-wide with no error. This proves the fix: the
 * job resolves and scans the document correctly with NO ambient tenant
 * context active before it runs (exactly the real queue-worker
 * condition), using only the firm_id it was constructed with.
 */
class ScanDocumentJobTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_scans_a_real_document_with_zero_ambient_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'scan_status' => DocumentScanStatus::Pending,
        ]));

        // The load-bearing precondition this test exists to enforce: no
        // firm context is active when the job runs, exactly like a real
        // queue worker picking up the job fresh.
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext('Expected a clean slate before the job runs.');

        $job = new ScanDocumentJob($document->id, $firm->id);
        $job->handle(app(VirusScanner::class), app(DocumentSecurityService::class));

        $fresh = $this->runWithFirmContext($firm, fn () => Document::query()->find($document->id));
        $this->assertNotSame(
            DocumentScanStatus::Pending,
            $fresh->scan_status,
            'The job must actually resolve and scan the document under FORCE RLS, not silently no-op.'
        );

        // The job must not leak firm context into whatever runs after it
        // in the same worker process.
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext('app.current_firm_id must be cleared after the job completes — no leak.');
    }

    public function test_a_cross_firm_document_id_cannot_be_scanned_under_the_wrong_firms_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentA = $this->runWithFirmContext($firmA, fn () => Document::factory()->create([
            'firm_id' => $firmA->id,
            'scan_status' => DocumentScanStatus::Pending,
        ]));

        // Firm A's document id, dispatched under Firm B's context — FORCE
        // RLS must exclude it, and the job must fail closed (no-op) rather
        // than scanning/mutating a document outside the supplied firm.
        $job = new ScanDocumentJob($documentA->id, $firmB->id);
        $job->handle(app(VirusScanner::class), app(DocumentSecurityService::class));

        $fresh = $this->runWithFirmContext($firmA, fn () => Document::query()->find($documentA->id));
        $this->assertSame(
            DocumentScanStatus::Pending,
            $fresh->scan_status,
            "Firm A's document must be untouched by a scan job dispatched under Firm B's context."
        );
    }
}
