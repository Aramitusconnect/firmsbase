<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDuplicateDetectionService;
use App\Services\ImportMappingService;
use App\Services\ImportPreviewService;
use App\Services\ImportRowValidationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * import_batches now carries FORCE ROW LEVEL SECURITY (Wave 9, see
 * database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php).
 *
 * FIXED (was a residual gap, closed in this same wave): earlier
 * test-verification for that activation found that ImportPreviewService::
 * preview()'s original docblock/design intent claimed only the trailing
 * $batch->update(...) call needed a runWithFirmContext() wrap. That was
 * incomplete — preview()'s duplicate-detection loop
 * (ImportDuplicateDetectionService::detect($row), which itself does an
 * unwrapped $row->importBatch lazy load of the now-forced import_batches
 * table) was not covered by any wrap and required ambient tenant context
 * to be active for the FULL duration of the preview() call, not merely
 * at entry — confirmed reachable, not hypothetical: calling preview()
 * with zero ambient context used to throw "Attempt to read property
 * entity_type on null" inside detect() for any entity type duplicate
 * detection covers (Client/Contact/Matter/Document/Party/Invoice/
 * PaymentPlan).
 *
 * The fix: preview()'s ENTIRE body is now wrapped in one
 * runWithFirmContext($batch->firm_id, ...) call (see ImportPreviewService
 * .php's own docblock), which safely nests around validateBatch()'s own
 * self-contained inner wrap (same firm — TenantContextService::
 * runWithFirmContext() snapshots/restores rather than unconditionally
 * clearing, so same-firm nesting is safe by design). This closes the gap
 * for preview()'s real, only production call path — there is still no
 * real production HTTP/Filament/job/console caller of preview() beyond
 * this test suite's own direct calls (confirmed by this wave's own
 * design review), but preview() itself is now genuinely correct
 * end-to-end with zero ambient context established by ITS caller —
 * proved directly by
 * test_preview_genuinely_succeeds_with_no_ambient_context_established_by_the_caller()
 * below, which is the real end-to-end proof of this fix (not merely a
 * test-side wrap papering over the gap, as the two pre-existing tests
 * below still do for convenience).
 */
class ImportPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportPreviewService $service;
    private ImportBatchService $batchService;
    private ImportMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService();
        $this->mappingService = new ImportMappingService($auditService);
        $this->batchService = new ImportBatchService($auditService);
        $this->service = new ImportPreviewService(
            new ImportRowValidationService($this->mappingService, $auditService),
            new ImportDuplicateDetectionService($auditService),
            $auditService,
        );
    }

    public function test_preview_does_not_create_any_production_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'display_name', 'target_field' => 'display_name', 'is_required' => true],
        ]);
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Alice Firm']]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->preview($batch));

        $this->assertSame(1, $result->totalRows);
        $this->assertDatabaseCount('clients', 0);
        $this->assertSame(ImportBatchStatus::PreviewReady, $this->runWithFirmContext($firm, fn () => $batch->fresh()->status));
    }

    public function test_preview_summarizes_valid_invalid_and_duplicate_counts(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'display_name', 'target_field' => 'display_name', 'is_required' => true],
        ]);
        $batch = $this->batchService->stageRows($batch, [
            ['display_name' => 'Valid Row'],
            [], // missing required field
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->preview($batch));

        $this->assertSame(2, $result->totalRows);
        $this->assertSame(1, $result->validRows);
        $this->assertSame(1, $result->invalidRows);
    }

    /**
     * The real end-to-end proof of the preview()-wrap fix: calls
     * preview() directly with truly zero ambient context established by
     * the caller (no runWithFirmContext() wrap around this call at all,
     * unlike the two tests above) and exercises the exact code path that
     * used to throw — the duplicate-detection loop's unwrapped
     * $row->importBatch lazy load inside
     * ImportDuplicateDetectionService::detect(). Before the fix this
     * threw "Attempt to read property entity_type on null"; now it must
     * both complete successfully AND report the genuine duplicate match,
     * proving detect() really executed against real, correctly-scoped
     * data rather than merely not crashing.
     */
    public function test_preview_genuinely_succeeds_with_no_ambient_context_established_by_the_caller(): void
    {
        $firm = Firm::factory()->create();
        $existing = \App\Models\Client::factory()->create(['firm_id' => $firm->id, 'email' => 'dup-preview-fix@example.test']);

        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'email', 'target_field' => 'email', 'is_required' => false],
        ]);
        $batch = $this->batchService->stageRows($batch, [
            ['email' => 'dup-preview-fix@example.test'],
        ]);

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        // $batch is the already-hydrated, in-memory object returned by
        // stageRows() above — not re-fetched via ->fresh() — exactly
        // matching preview()'s own documented contract that $batch->
        // firm_id is already an in-memory attribute requiring no extra
        // query before its internal wrap begins.
        $result = $this->service->preview($batch);

        $this->assertNoDatabaseTenantContext('preview() must clear its own internal context wrap before returning, leaving the caller exactly as it found it.');
        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->validRows);
        $this->assertSame(1, $result->duplicateRows, 'detect() must have genuinely run under preview()\'s own wrap and found the real duplicate, not merely avoided crashing.');
        $this->assertSame(ImportBatchStatus::PreviewReady, $this->runWithFirmContext($firm, fn () => $batch->fresh()->status));

        $row = $this->runWithFirmContext($firm, fn () => $batch->rows()->first());
        $this->assertTrue($row->is_duplicate);
        $this->assertSame($existing->id, $row->duplicate_of_id);
        $this->assertSame(\App\Models\Client::class, $row->duplicate_of_type);
    }
}
