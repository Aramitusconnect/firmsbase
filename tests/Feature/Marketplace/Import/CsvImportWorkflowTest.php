<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Import;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryProfileVersion;
use App\Marketplace\Services\MarketplaceCsvIngestionService;
use App\Marketplace\Services\MarketplaceImportApplyService;
use App\Marketplace\Services\MarketplaceImportDuplicateDetectionService;
use App\Marketplace\Services\MarketplaceImportValidationService;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CsvImportWorkflowTest — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 9. Sections 26-27, 52-55: CSV treated as fully untrusted
 * input, multi-signal duplicate detection never auto-merging, and the
 * source-precedence rule (verified/claimed data outranks a CSV import).
 */
final class CsvImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "legal_name,display_name,phone,website,public_email,description,city,state,postal_code,founding_year\n";

    private MarketplaceCsvIngestionService $ingestion;

    private MarketplaceImportValidationService $validation;

    private MarketplaceImportDuplicateDetectionService $duplicates;

    private MarketplaceImportApplyService $apply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestion = app(MarketplaceCsvIngestionService::class);
        $this->validation = app(MarketplaceImportValidationService::class);
        $this->duplicates = app(MarketplaceImportDuplicateDetectionService::class);
        $this->apply = app(MarketplaceImportApplyService::class);
    }

    private function csvFile(string $content, string $name = 'firms.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // ------------------------------------------------------------
    // Ingestion — untrusted input handling.
    // ------------------------------------------------------------

    public function test_ingesting_a_valid_csv_stages_a_batch_and_its_rows(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER
            ."Acme Legal PLLC,Acme Legal,5555550100,https://acme-legal.example.com,hello@acme.example.com,A firm.,Detroit,MI,48201,2005\n"
            ."Beta Law Group,Beta Law,5555550200,https://beta-law.example.com,,,Lansing,MI,48901,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(DirectoryImportBatchStatus::Staged, $batch->status);
        $this->assertSame('firms.csv', $batch->original_filename);
        $this->assertSame($admin->id, $batch->created_by_platform_admin_id);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->rows()->count());
    }

    public function test_ingesting_a_non_csv_extension_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile(self::HEADER, 'firms.exe'), $admin);
    }

    public function test_ingesting_an_oversized_file_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $oversized = UploadedFile::fake()->create('huge.csv', 26_000); // ~26MB, over the 25MB cap.

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($oversized, $admin);
    }

    public function test_ingesting_a_file_that_fails_the_virus_scan_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";

        $this->expectException(\RuntimeException::class);
        // FakeVirusScanner flags any storage path containing "infected".
        $this->ingestion->ingest($this->csvFile($csv, 'infected-firms.csv'), $admin);

        $this->assertSame(0, DirectoryImportBatch::query()->count(), 'An infected upload must never produce a staged batch.');
    }

    public function test_ingesting_a_csv_missing_required_headers_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile("legal_name,phone\nAcme,555\n"), $admin);
    }

    public function test_a_malformed_row_is_flagged_invalid_without_aborting_the_rest_of_the_batch(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER
            ."Acme Legal PLLC,Acme Legal,5555550100,,,,Detroit,MI,,\n"
            ."Malformed Row,Only Three Fields,555\n" // wrong column count
            ."Beta Law Group,Beta Law,5555550200,,,,Lansing,MI,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(3, $batch->rows()->count());
        $malformed = $batch->rows()->where('row_number', 2)->first();
        $this->assertSame(DirectoryImportRowStatus::Invalid, $malformed->status);
        $this->assertNotEmpty($malformed->errors);
        $this->assertSame(DirectoryImportRowStatus::Pending, $batch->rows()->where('row_number', 1)->first()->status);
        $this->assertSame(DirectoryImportRowStatus::Pending, $batch->rows()->where('row_number', 3)->first()->status);
    }

    public function test_formula_injection_payloads_are_neutralized_on_ingestion(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."=cmd|'/c calc'!A1,Display Name,,,,,,,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $row = $batch->rows()->first();
        $this->assertStringStartsWith("'=", $row->raw_data['legal_name']);
    }

    public function test_a_utf8_bom_is_stripped_so_the_header_still_matches(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = "\xEF\xBB\xBF".self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(1, $batch->total_rows);
        $this->assertSame('Acme Legal PLLC', $batch->rows()->first()->raw_data['legal_name']);
    }

    // ------------------------------------------------------------
    // Validation.
    // ------------------------------------------------------------

    public function test_validate_batch_marks_well_formed_rows_valid_with_mapped_data(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550100,https://acme.example.com,,,Detroit,MI,48201,2005\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->validation->validateBatch($batch);

        $row = $batch->rows()->first();
        $this->assertSame(DirectoryImportRowStatus::Valid, $row->status);
        $this->assertSame('Acme Legal', $row->mapped_data['display_name']);
        $this->assertSame('acme legal', $row->mapped_data['name_normalized']);
        $this->assertSame(2005, $row->mapped_data['founding_year']);
        $this->assertSame(DirectoryImportBatchStatus::Validated, $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->valid_rows);
    }

    public function test_validate_batch_marks_a_row_missing_required_fields_invalid(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER.",,,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->validation->validateBatch($batch);

        $row = $batch->rows()->first();
        $this->assertSame(DirectoryImportRowStatus::Invalid, $row->status);
        $this->assertContains('legal_name is required.', $row->errors);
        $this->assertContains('display_name is required.', $row->errors);
    }

    public function test_validate_batch_rejects_an_implausible_founding_year(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,1500\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->validation->validateBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Invalid, $batch->rows()->first()->status);
    }

    // ------------------------------------------------------------
    // Duplicate detection — multi-signal, never auto-merges.
    // ------------------------------------------------------------

    public function test_duplicate_detection_matches_by_normalized_name(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $row = $batch->rows()->first();
        $this->assertSame(DirectoryImportRowStatus::Duplicate, $row->status);
        $this->assertNotNull($row->duplicate_of_directory_firm_id);
    }

    public function test_duplicate_detection_matches_by_phone(): void
    {
        DirectoryFirm::factory()->create(['phone' => '5555550100']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Totally Different Name PLLC,Totally Different,5555550100,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Duplicate, $batch->rows()->first()->status);
    }

    public function test_duplicate_detection_matches_by_website_domain(): void
    {
        DirectoryFirm::factory()->create(['website' => 'https://www.acme-legal.example.com']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Different Name PLLC,Different Name,,https://acme-legal.example.com/contact,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Duplicate, $batch->rows()->first()->status);
    }

    public function test_a_genuinely_new_firm_is_not_flagged_a_duplicate(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Existing Firm', 'name_normalized' => 'existing firm', 'phone' => '5555559999']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Brand New Firm PLLC,Brand New Firm,5555550100,https://brandnew.example.com,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Valid, $batch->rows()->first()->status);
    }

    // ------------------------------------------------------------
    // Apply — creation, source-precedence, source-rights gate, audit.
    // ------------------------------------------------------------

    public function test_apply_creates_a_new_draft_firm_from_a_valid_non_duplicate_row(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550100,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);
        $this->apply->confirmSourceRights($batch);

        $applied = $this->apply->apply($batch->fresh(), $admin);

        $firm = DirectoryFirm::query()->where('name_normalized', 'acme legal')->first();
        $this->assertNotNull($firm);
        $this->assertSame(DirectoryPublicationState::Draft, $firm->publication_state);
        $this->assertSame(DataProvenanceSourceType::CsvImport, $firm->source_type);
        $this->assertSame((string) $batch->uuid, $firm->source_reference);
        $this->assertSame(1, $applied->applied_rows);
        $this->assertSame(DirectoryImportBatchStatus::Applied, $applied->status);
    }

    public function test_apply_refuses_without_source_rights_confirmation(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);

        $this->expectException(\RuntimeException::class);
        $this->apply->apply($batch->fresh(), $admin);
    }

    public function test_apply_marks_batch_source_approval_required_when_refused(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);

        try {
            $this->apply->apply($batch->fresh(), $admin);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(DirectoryImportBatchStatus::SourceApprovalRequired, $batch->fresh()->status);
        $this->assertSame(0, DirectoryFirm::query()->count(), 'Nothing may be created before source rights are confirmed.');
    }

    public function test_apply_with_an_explicit_update_decision_updates_an_unclaimed_duplicate_and_records_a_profile_version(): void
    {
        $existing = DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal', 'phone' => null]);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550199,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);
        $this->apply->confirmSourceRights($batch);
        $row = $batch->rows()->first();

        $applied = $this->apply->apply($batch->fresh(), $admin, [$row->id => 'update']);

        $this->assertSame('5555550199', $existing->fresh()->phone);
        $this->assertSame(1, $applied->applied_rows);

        $version = DirectoryProfileVersion::query()->where('directory_firm_id', $existing->id)->first();
        $this->assertNotNull($version);
        $this->assertSame('csv_import', $version->actor_type);
    }

    public function test_apply_with_no_explicit_decision_on_a_duplicate_defaults_to_skip(): void
    {
        $existing = DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal', 'phone' => null]);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550199,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);
        $this->apply->confirmSourceRights($batch);

        $applied = $this->apply->apply($batch->fresh(), $admin);

        $this->assertNull($existing->fresh()->phone, 'A duplicate must never be silently applied without an explicit decision.');
        $this->assertSame(DirectoryImportRowStatus::Skipped, $batch->rows()->first()->status);
        $this->assertSame(1, $applied->skipped_rows);
    }

    public function test_apply_refuses_to_update_an_already_claimed_duplicate_even_with_an_explicit_update_decision(): void
    {
        $existing = DirectoryFirm::factory()->claimed()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal', 'phone' => '5555550001']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550199,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);
        $this->apply->confirmSourceRights($batch);
        $row = $batch->rows()->first();

        $this->apply->apply($batch->fresh(), $admin, [$row->id => 'update']);

        $this->assertSame('5555550001', $existing->fresh()->phone, 'A claimed listing must never be overwritten by a CSV import, regardless of the admin\'s row decision.');
        $this->assertSame(DirectoryImportRowStatus::Skipped, $batch->rows()->first()->status);
    }

    public function test_apply_refuses_to_update_a_more_recently_verified_duplicate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550199,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);
        $existing = DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal', 'phone' => '5555550001']);
        // The candidate wasn't matched yet at detection time above (it didn't exist), so re-run detection now that it does.
        $this->duplicates->detectBatch($batch->fresh());
        // A full minute after the batch, not just now() — the
        // timestamps() columns here store whole-second precision, and
        // this test's own steps can otherwise land in the same second
        // as batch creation, making a bare now() an unreliable "more
        // recent than" fixture.
        $existing->update(['last_verified_at' => $batch->fresh()->created_at->addMinute()]);
        $this->apply->confirmSourceRights($batch->fresh());
        $row = $batch->fresh()->rows()->first();

        $this->apply->apply($batch->fresh(), $admin, [$row->id => 'update']);

        $this->assertSame('5555550001', $existing->fresh()->phone, 'A more-recently-verified listing must never be overwritten by an older CSV import.');
        $this->assertSame(DirectoryImportRowStatus::Skipped, $batch->fresh()->rows()->first()->status);
    }

    public function test_apply_records_a_null_firm_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $batch = $this->stageValidateDetect($csv, $admin);
        $this->apply->confirmSourceRights($batch);

        $this->apply->apply($batch->fresh(), $admin);

        $event = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->whereNull('firm_id')
            ->where('event_type', 'marketplace_import_applied')
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_id);
    }

    public function test_preview_returns_a_dry_run_summary_with_no_writes(): void
    {
        DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Existing', 'name_normalized' => 'existing']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER
            ."New Firm PLLC,New Firm,,,,,,,,\n"
            ."Existing PLLC,Existing,,,,,,,,\n"
            .",,,,,,,,,\n"; // invalid row
        $batch = $this->stageValidateDetect($csv, $admin);
        $countBefore = DirectoryFirm::query()->count();

        $summary = $this->apply->preview($batch->fresh());

        $this->assertSame(1, $summary['creatable']);
        $this->assertSame(1, $summary['updatable']);
        $this->assertSame(1, $summary['invalid']);
        $this->assertSame($countBefore, DirectoryFirm::query()->count(), 'preview() must never write anything.');
    }

    // ------------------------------------------------------------
    // RLS exemption.
    // ------------------------------------------------------------

    public function test_import_tables_are_genuinely_exempt_from_row_level_security(): void
    {
        foreach (['directory_import_batches', 'directory_import_rows'] as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "{$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relrowsecurity, "RLS must NOT be enabled on {$table}.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "FORCE RLS must NOT be enabled on {$table}.");
        }
    }

    // ------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------

    private function stageValidateDetect(string $csv, PlatformAdmin $admin): DirectoryImportBatch
    {
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch->fresh());
        $this->duplicates->detectBatch($batch->fresh());

        return $batch->fresh();
    }
}
