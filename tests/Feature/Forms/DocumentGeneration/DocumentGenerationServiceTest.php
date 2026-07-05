<?php

namespace Tests\Feature\Forms\DocumentGeneration;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\DocumentTemplateVersionStatus;
use App\Enums\FirmUserRole;
use App\Enums\GeneratedDocumentStatus;
use App\Models\Client;
use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\DeterministicFieldResolutionService;
use App\Services\DocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentGenerationService(new DeterministicFieldResolutionService());
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

        $document = \App\Models\GeneratedDocument::find($result->generatedDocumentId);
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
}
