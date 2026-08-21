<?php

namespace Tests\Feature\Forms\DocumentGeneration;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\DocumentTemplateVersionStatus;
use App\Enums\FirmUserRole;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Client;
use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Services\DeterministicFieldResolutionService;
use App\Services\DocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = new DocumentGenerationService(new DeterministicFieldResolutionService);
    }

    public function test_generate_blocks_a_non_active_template_version(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create(['status' => DocumentTemplateVersionStatus::Draft->value]);

        $this->expectException(\RuntimeException::class);
        $this->service->generate($version, $actor, $firm->id);
    }

    public function test_generate_resolves_merge_fields_and_records_used_sample_content_true_for_sample_only_version(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create(['display_name' => 'Ana Torres']);
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create([
            'status' => DocumentTemplateVersionStatus::Active->value,
            'content_status' => DocumentTemplateContentStatus::SampleOnly->value,
            'merge_fields_schema' => [
                ['token' => 'client_name', 'source_entity' => 'client', 'source_path' => 'client.display_name', 'transform' => 'none'],
            ],
        ]);

        $result = $this->service->generate($version, $actor, $firm->id, null, $client);

        $this->assertTrue($result->usedSampleContent);
        $this->assertSame('Ana Torres', $result->resolvedMergeValues['client_name']);
        $this->assertSame(GeneratedDocumentStatus::Draft, $result->status);
        $this->assertStringContainsString('generated-documents/firm-'.$firm->id, $result->simulatedStoragePath);

        $document = GeneratedDocument::find($result->generatedDocumentId);
        $this->assertTrue($document->used_sample_content);
        $this->assertSame($firm->id, $document->firm_id);
    }

    public function test_generate_records_used_sample_content_false_for_reviewed_approved_version(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create([
            'status' => DocumentTemplateVersionStatus::Active->value,
            'content_status' => DocumentTemplateContentStatus::ReviewedApproved->value,
            'merge_fields_schema' => [],
        ]);

        $result = $this->service->generate($version, $actor, $firm->id);

        $this->assertFalse($result->usedSampleContent);
    }

    public function test_unresolvable_merge_field_returns_null_without_error(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create([
            'status' => DocumentTemplateVersionStatus::Active->value,
            'merge_fields_schema' => [
                ['token' => 'client_name', 'source_entity' => 'client', 'source_path' => 'client.display_name', 'transform' => 'none'],
            ],
        ]);

        $result = $this->service->generate($version, $actor, $firm->id, null, null);

        $this->assertNull($result->resolvedMergeValues['client_name']);
    }

    public function test_generate_writes_a_real_rendered_pdf_to_storage(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $client = Client::factory()->forFirm($firm)->create(['display_name' => 'Jordan Lee']);
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create([
            'status' => DocumentTemplateVersionStatus::Active->value,
            'content_status' => DocumentTemplateContentStatus::ReviewedApproved->value,
            'body_template' => 'Dear {{client_name}}, this is a real generated document.',
            'merge_fields_schema' => [
                ['token' => 'client_name', 'source_entity' => 'client', 'source_path' => 'client.display_name', 'transform' => 'none'],
            ],
        ]);

        $result = $this->service->generate($version, $actor, $firm->id, $matter, $client);

        $document = GeneratedDocument::find($result->generatedDocumentId);

        $this->assertSame('local', $document->storage_disk);
        $this->assertNotNull($document->storage_path);
        $this->assertStringStartsWith("generated-documents/{$firm->id}/{$matter->id}/", $document->storage_path);
        $this->assertStringEndsWith('.pdf', $document->storage_path);

        Storage::disk($document->storage_disk)->assertExists($document->storage_path);
        $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);

        // simulated_storage_path is preserved, in its original format,
        // and remains distinct from the real storage_path.
        $this->assertStringContainsString('generated-documents/firm-'.$firm->id, $document->simulated_storage_path);
        $this->assertNotSame($document->simulated_storage_path, $document->storage_path);
    }

    /**
     * Non-payment completion program (staging deployment mission):
     * proves generate() writes to the app's CONFIGURED default disk
     * (config('filesystems.default')), never a hardcoded 'local'
     * literal — a hardcoded literal would permanently pin every
     * generated document to the ECS task's own non-durable filesystem
     * regardless of what FILESYSTEM_DISK is actually set to in
     * staging/production ('s3').
     */
    public function test_generate_writes_to_the_configured_default_disk_not_a_hardcoded_local_literal(): void
    {
        Config::set('filesystems.default', 's3');
        Storage::fake('s3');

        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create([
            'status' => DocumentTemplateVersionStatus::Active->value,
            'content_status' => DocumentTemplateContentStatus::ReviewedApproved->value,
            'body_template' => 'Configured-disk proof.',
            'merge_fields_schema' => [],
        ]);

        $result = $this->service->generate($version, $actor, $firm->id, $matter);

        $document = GeneratedDocument::find($result->generatedDocumentId);

        $this->assertSame('s3', $document->storage_disk, 'The generated document must be recorded on the CONFIGURED default disk, not a hardcoded local literal.');
        Storage::disk('s3')->assertExists($document->storage_path);
        Storage::disk('local')->assertMissing($document->storage_path);
    }

    public function test_generate_records_a_real_sha256_hash_of_the_rendered_bytes(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create([
            'status' => DocumentTemplateVersionStatus::Active->value,
            'content_status' => DocumentTemplateContentStatus::ReviewedApproved->value,
            'merge_fields_schema' => [],
        ]);

        $result = $this->service->generate($version, $actor, $firm->id);

        $document = GeneratedDocument::find($result->generatedDocumentId);
        $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
        $expectedHash = hash('sha256', $bytes);

        $this->assertDatabaseHas('document_hashes', [
            'generated_document_id' => $document->id,
            'hash_value' => $expectedHash,
            'algorithm' => HashAlgorithm::Sha256->value,
            'source_document_type' => SignatureSourceDocumentType::GeneratedDocument->value,
            'recorded_by_firm_user_id' => $actor->id,
        ]);
    }
}
