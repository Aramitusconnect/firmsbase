<?php

namespace Tests\Feature\Forms\Drafts;

use App\Enums\FirmUserRole;
use App\Enums\FormDraftStatus;
use App\Enums\FormFieldType;
use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\FormField;
use App\Models\FormTemplateVersion;
use App\Models\Matter;
use App\Services\DeterministicFieldResolutionService;
use App\Services\FormDraftGenerationService;
use App\Services\FormFieldService;
use App\Services\FormMappingRuleService;
use App\Services\FormMissingDataDetectionService;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormDraftGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormDraftGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormDraftGenerationService(
            new DeterministicFieldResolutionService(),
            new FormMissingDataDetectionService(),
        );
    }

    public function test_generate_blocks_a_non_active_version(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = FormTemplateVersion::factory()->retired()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->generate($matter, $version, $actor);
    }

    public function test_generate_creates_a_draft_with_mapped_values(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create(['display_name' => 'Maria Gonzalez']);
        $matter = Matter::factory()->forFirm($firm)->create(['client_id' => $client->id]);
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $version = FormTemplateVersion::factory()->create();
        $field = (new FormFieldService())->createField($version, 'full_name', 'Full Name', FormFieldType::Text, true, 1);
        $admin = PlatformAdmin::factory()->create();
        (new FormMappingRuleService())->createRule(
            $version, $field, FormMappingSourceEntity::Client, 'client.display_name', FormMappingTransform::None, $admin
        );

        $result = $this->service->generate($matter, $version, $actor, $client);

        $this->assertSame(1, $result->valuesGenerated);
        $this->assertTrue($result->usedSampleMapping);
        $this->assertSame(0, $result->missingRequiredCount);

        $draft = \App\Models\FormDraft::find($result->formDraftId);
        $this->assertSame($firm->id, $draft->firm_id);
        $this->assertSame('Maria Gonzalez', $draft->values()->first()->value);
    }

    public function test_generate_marks_draft_needs_data_when_a_required_field_is_unresolvable(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $version = FormTemplateVersion::factory()->create();
        $field = (new FormFieldService())->createField($version, 'company_name', 'Company Name', FormFieldType::Text, true, 1);
        $admin = PlatformAdmin::factory()->create();
        // Client source but no client passed in -> unresolvable.
        (new FormMappingRuleService())->createRule(
            $version, $field, FormMappingSourceEntity::Client, 'client.legal_name', FormMappingTransform::None, $admin
        );

        $result = $this->service->generate($matter, $version, $actor, null);

        $draft = \App\Models\FormDraft::find($result->formDraftId);
        $this->assertSame(FormDraftStatus::NeedsData, $draft->status);
        $this->assertSame(1, $result->missingRequiredCount);
    }

    public function test_form_template_version_id_is_immutable_after_creation(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = FormTemplateVersion::factory()->create();

        $result = $this->service->generate($matter, $version, $actor);
        $draft = \App\Models\FormDraft::find($result->formDraftId);

        $otherVersion = FormTemplateVersion::factory()->create();

        $this->expectException(\LogicException::class);
        $draft->update(['form_template_version_id' => $otherVersion->id]);
    }
}
