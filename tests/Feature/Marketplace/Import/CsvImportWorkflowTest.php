<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Import;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
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
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
    // MyAttorney final hardening mission — path traversal (finding 5).
    // ------------------------------------------------------------

    public function test_a_path_traversal_original_filename_cannot_escape_the_quarantine_directory(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv, '../../../../evil.csv'), $admin);

        $this->assertSame(1, $batch->total_rows, 'A hostile filename must not block a legitimate CSV from importing.');
        $this->assertFalse(Storage::disk('local')->exists('evil.csv'), 'The quarantine write must never land outside marketplace-imports/quarantine/, at the disk root or anywhere else.');
        $this->assertStringEndsWith('.csv', $batch->original_filename);
        $this->assertStringNotContainsString('/', $batch->original_filename, 'The stored display filename must never itself contain a path separator.');
        $this->assertStringNotContainsString('..', $batch->original_filename);
    }

    public function test_a_windows_style_path_traversal_filename_is_also_neutralized(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv, '..\\..\\..\\evil.csv'), $admin);

        $this->assertSame(1, $batch->total_rows);
        $this->assertStringNotContainsString('\\', $batch->original_filename);
        $this->assertStringNotContainsString('/', $batch->original_filename);
    }

    public function test_no_quarantine_file_is_left_behind_after_a_hostile_filename_upload(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $before = collect(Storage::disk('local')->allFiles('marketplace-imports/quarantine'));

        $this->ingestion->ingest($this->csvFile($csv, '../../evil.csv'), $admin);

        $after = collect(Storage::disk('local')->allFiles('marketplace-imports/quarantine'));
        $this->assertCount($before->count(), $after, 'The quarantine copy must always be cleaned up, regardless of the original filename.');
    }

    // ------------------------------------------------------------
    // MyAttorney final hardening mission — content validation (finding 2).
    // ------------------------------------------------------------

    public function test_a_binary_payload_renamed_dot_csv_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        // PNG magic bytes.
        $payload = "\x89PNG\r\n\x1a\n".random_bytes(64);

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile($payload, 'looks-like.csv'), $admin);
    }

    public function test_a_windows_executable_renamed_dot_csv_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $payload = 'MZ'.str_repeat("\x90", 62);

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile($payload, 'setup.csv'), $admin);
    }

    public function test_a_zip_family_archive_renamed_dot_csv_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $payload = "PK\x03\x04".random_bytes(32);

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile($payload, 'archive.csv'), $admin);
    }

    public function test_a_nul_byte_containing_file_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $payload = self::HEADER."Acme\x00Legal,Acme,,,,,,,,\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile($payload), $admin);
    }

    public function test_invalid_utf8_content_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        // \xFF is never valid as a UTF-8 leading byte.
        $payload = self::HEADER."Acme Legal PLLC,Acme\xFF Legal,,,,,,,,\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile($payload), $admin);
    }

    public function test_an_empty_file_is_handled_safely_not_a_fatal_error(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion->ingest($this->csvFile(''), $admin);

        $this->assertSame(0, DirectoryImportBatch::query()->count());
    }

    public function test_a_normal_valid_csv_is_completely_unaffected_by_content_validation(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Café Legal PLLC,Café Legal,,,,Über-friendly firm.,,,,\n"; // real multibyte UTF-8

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(DirectoryImportRowStatus::Pending, $batch->rows()->first()->status);
    }

    // ------------------------------------------------------------
    // MyAttorney final hardening mission — row limit (finding 3).
    // ------------------------------------------------------------

    public function test_a_csv_at_exactly_the_row_limit_is_accepted(): void
    {
        config(['marketplace.import_max_rows' => 3]);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER.str_repeat("Acme Legal PLLC,Acme Legal,,,,,,,,\n", 3);

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(3, $batch->total_rows);
    }

    public function test_a_csv_one_row_below_the_limit_is_accepted(): void
    {
        config(['marketplace.import_max_rows' => 3]);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER.str_repeat("Acme Legal PLLC,Acme Legal,,,,,,,,\n", 2);

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(2, $batch->total_rows);
    }

    public function test_a_csv_one_row_over_the_limit_is_rejected_with_no_batch_created(): void
    {
        config(['marketplace.import_max_rows' => 3]);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER.str_repeat("Acme Legal PLLC,Acme Legal,,,,,,,,\n", 4);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->ingestion->ingest($this->csvFile($csv), $admin);
        } finally {
            $this->assertSame(0, DirectoryImportBatch::query()->count(), 'An over-limit CSV must create no batch and no rows at all — never a partial import.');
            $this->assertSame(0, DirectoryImportRow::query()->count());
        }
    }

    public function test_a_pathologically_large_single_row_is_flagged_invalid_not_fatal(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER.'Acme Legal PLLC,'.str_repeat('x', 70_000).",,,,,,,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(DirectoryImportRowStatus::Invalid, $batch->rows()->first()->status);
    }

    // ------------------------------------------------------------
    // MyAttorney final hardening mission — import lifecycle audit (finding 4).
    // ------------------------------------------------------------

    public function test_upload_writes_an_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";

        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $event = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->whereNull('firm_id')
            ->where('event_type', 'marketplace_import_uploaded')
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_id);
        $metadata = $event->metadata;
        $this->assertSame($batch->id, $metadata['directory_import_batch_id']);
        $this->assertSame(1, $metadata['total_rows']);
        $this->assertArrayNotHasKey('raw_data', $metadata);
    }

    public function test_validate_writes_an_audit_event_only_when_an_actor_is_supplied(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);

        $this->validation->validateBatch($batch->fresh());
        $noActorCount = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()->where('event_type', 'marketplace_import_validated')->count());
        $this->assertSame(0, $noActorCount, 'No actor supplied means no audit event — matches every other optional-actor audit call site in this codebase.');

        $this->validation->validateBatch($batch->fresh(), $admin);
        $event = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()->where('event_type', 'marketplace_import_validated')->orderByDesc('id')->first());
        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_id);
    }

    public function test_duplicate_detection_writes_an_audit_event(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch->fresh());

        $this->duplicates->detectBatch($batch->fresh(), $admin);

        $event = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->where('event_type', 'marketplace_import_duplicates_evaluated')
            ->orderByDesc('id')
            ->first());
        $this->assertNotNull($event);
        $this->assertSame(1, $event->metadata['duplicate_rows']);
    }

    public function test_import_audit_events_never_carry_raw_row_content(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Acme Legal PLLC,Acme Legal,5555550100,,SensitiveDescriptionText,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch->fresh(), $admin);
        $this->duplicates->detectBatch($batch->fresh(), $admin);

        $events = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()->where('category', 'marketplace_import')->get());
        $this->assertGreaterThan(0, $events->count());
        foreach ($events as $event) {
            $serialized = json_encode($event->metadata);
            $this->assertStringNotContainsString('SensitiveDescriptionText', $serialized);
            $this->assertStringNotContainsString('5555550100', $serialized);
        }
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
    // MyAttorney final hardening mission — stronger, deterministic
    // duplicate normalization (finding 6) + match reasons (finding 12).
    // ------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nameVariantProvider(): iterable
    {
        yield 'trailing comma before suffix' => ['Smith Law, PLLC'];
        yield 'periods within the suffix and different casing' => ['SMITH LAW P.L.L.C.'];
        yield 'repeated internal whitespace' => ['Smith   Law PLLC'];
        yield 'leading/trailing whitespace' => ['  Smith Law PLLC  '];
    }

    #[DataProvider('nameVariantProvider')]
    public function test_duplicate_detection_matches_conservative_name_variants(string $variant): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Smith Law PLLC', 'name_normalized' => 'smith law pllc']);
        $admin = PlatformAdmin::factory()->create();
        $quoted = '"'.str_replace('"', '""', $variant).'"';
        $csv = self::HEADER."{$quoted},{$quoted},,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Duplicate, $batch->rows()->first()->status, "Failed to match name variant: {$variant}");
    }

    public function test_similarly_named_but_genuinely_different_firms_are_never_conflated(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Smith Law', 'name_normalized' => 'smith law']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Smith Legal Group PLLC,Smith Legal Group,,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Valid, $batch->rows()->first()->status, 'A conservative normalizer must never treat "Smith Law" and "Smith Legal Group" as the same firm.');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function phoneVariantProvider(): iterable
    {
        yield 'parentheses and hyphen' => ['(313) 555-1212'];
        yield 'hyphen only' => ['313-555-1212'];
        yield 'digits only' => ['3135551212'];
    }

    #[DataProvider('phoneVariantProvider')]
    public function test_duplicate_detection_matches_conservative_phone_variants(string $variant): void
    {
        DirectoryFirm::factory()->create(['phone' => '3135551212']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Totally Different Name PLLC,Totally Different,{$variant},,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Duplicate, $batch->rows()->first()->status, "Failed to match phone variant: {$variant}");
    }

    public function test_a_leading_country_code_is_deliberately_not_normalized_away(): void
    {
        // Documented design choice, not a gap: stripping a "+1" country
        // code from an 11-digit number to compare it against a bare
        // 10-digit number risks conflating two genuinely different
        // numbers that merely share the same last 10 digits.
        DirectoryFirm::factory()->create(['phone' => '3135551212']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Different Name PLLC,Different Name,+1 313 555 1212,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        $this->assertSame(DirectoryImportRowStatus::Valid, $batch->rows()->first()->status);
    }

    public function test_a_short_digit_sequence_never_falsely_matches_on_phone(): void
    {
        DirectoryFirm::factory()->create(['phone' => '123456']);
        $admin = PlatformAdmin::factory()->create();
        $csv = self::HEADER."Different Name PLLC,Different Name,123456,,,,,,,\n";
        $batch = $this->ingestion->ingest($this->csvFile($csv), $admin);
        $this->validation->validateBatch($batch);

        $this->duplicates->detectBatch($batch);

        // Exact-string match still fires (unchanged existing behavior)
        // — this proves the fallback's 7-digit floor doesn't SUPPRESS
        // a real match, only the fuzzy fallback path.
        $this->assertSame(DirectoryImportRowStatus::Duplicate, $batch->rows()->first()->status);
    }

    public function test_find_duplicate_candidate_reports_every_signal_that_matched(): void
    {
        $existing = DirectoryFirm::factory()->create([
            'display_name' => 'Acme Legal',
            'name_normalized' => 'acme legal',
            'phone' => '3135551212',
        ]);

        $result = $this->duplicates->findDuplicateCandidate([
            'name_normalized' => 'acme legal',
            'phone' => '3135551212',
        ]);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result['firm']->id);
        $this->assertContains('Same normalized legal name', $result['reasons']);
        $this->assertContains('Same normalized phone number', $result['reasons']);
        $this->assertContains('Multiple matching attributes', $result['reasons']);
    }

    public function test_find_duplicate_candidate_reports_only_the_single_signal_that_matched(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Acme Legal', 'name_normalized' => 'acme legal']);

        $result = $this->duplicates->findDuplicateCandidate(['name_normalized' => 'acme legal']);

        $this->assertNotNull($result);
        $this->assertSame(['Same normalized legal name'], $result['reasons']);
    }

    public function test_find_duplicate_candidate_returns_null_for_no_match(): void
    {
        $this->assertNull($this->duplicates->findDuplicateCandidate(['name_normalized' => 'nobody here']));
    }

    // ------------------------------------------------------------
    // MyAttorney final hardening mission, finding 7 — Directory
    // Attorneys had no duplicate check of any kind before this
    // mission; findAttorneyDuplicateCandidate() is genuinely new.
    // ------------------------------------------------------------

    public function test_find_attorney_duplicate_candidate_matches_by_normalized_name(): void
    {
        $existing = DirectoryAttorney::factory()->create(['name' => 'Jane Smith', 'name_normalized' => 'jane smith']);

        $result = $this->duplicates->findAttorneyDuplicateCandidate(['name' => 'JANE   SMITH']);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result['attorney']->id);
        $this->assertSame(['Same normalized name'], $result['reasons']);
    }

    public function test_find_attorney_duplicate_candidate_matches_by_bar_number(): void
    {
        $existing = DirectoryAttorney::factory()->create(['bar_number' => 'P12345']);

        $result = $this->duplicates->findAttorneyDuplicateCandidate(['name' => 'Totally Different Name', 'bar_number' => 'P12345']);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result['attorney']->id);
        $this->assertContains('Same bar number', $result['reasons']);
    }

    public function test_find_attorney_duplicate_candidate_returns_null_for_a_genuinely_new_attorney(): void
    {
        DirectoryAttorney::factory()->create(['name' => 'Existing Attorney', 'name_normalized' => 'existing attorney', 'bar_number' => 'P00000']);

        $result = $this->duplicates->findAttorneyDuplicateCandidate(['name' => 'Brand New Attorney', 'bar_number' => 'P99999']);

        $this->assertNull($result);
    }

    public function test_find_attorney_duplicate_candidate_ignores_the_specified_record(): void
    {
        $existing = DirectoryAttorney::factory()->create(['name' => 'Jane Smith', 'name_normalized' => 'jane smith', 'bar_number' => 'P12345']);

        $result = $this->duplicates->findAttorneyDuplicateCandidate(['name' => 'Jane Smith', 'bar_number' => 'P12345'], $existing->id);

        $this->assertNull($result, 'Editing a record must never flag it as a duplicate of itself.');
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
