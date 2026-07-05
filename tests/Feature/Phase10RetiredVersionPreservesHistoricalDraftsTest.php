<?php

namespace Tests\Feature;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Services\DeterministicFieldResolutionService;
use App\Services\FormDraftGenerationService;
use App\Services\FormMissingDataDetectionService;
use App\Services\FormTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the two related project rules together: (1) retiring a form
 * template version must never mutate a historical FormDraft that
 * already references it, and (2) a draft cannot be generated FROM a
 * retired version in the first place. FormTemplateService::retire()
 * only ever writes to the version row itself — this is the entire
 * mechanism.
 */
class Phase10RetiredVersionPreservesHistoricalDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retiring_a_version_does_not_mutate_a_pre_existing_draft(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $admin = PlatformAdmin::factory()->create();

        $templateService = new FormTemplateService();
        $template = $templateService->registerFormCode('I-765', 'Application for Employment Authorization');
        $version = $templateService->activate($templateService->createVersion($template, '01/01/24', $admin));

        $draftService = new FormDraftGenerationService(
            new DeterministicFieldResolutionService(),
            new FormMissingDataDetectionService(),
        );
        $result = $draftService->generate($matter, $version, $actor);
        $draft = \App\Models\FormDraft::find($result->formDraftId);

        $beforeRetireVersionId = $draft->form_template_version_id;
        $beforeRetireStatus = $draft->status;
        $beforeRetireValuesCount = $draft->values()->count();

        $templateService->retire($version, 'edition superseded by USCIS');

        $draft->refresh();

        $this->assertSame($beforeRetireVersionId, $draft->form_template_version_id);
        $this->assertSame($beforeRetireStatus, $draft->status);
        $this->assertSame($beforeRetireValuesCount, $draft->values()->count());
    }

    public function test_a_new_draft_cannot_be_generated_from_a_retired_version(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $admin = PlatformAdmin::factory()->create();

        $templateService = new FormTemplateService();
        $template = $templateService->registerFormCode('AR-11', 'Alien Change of Address Card');
        $version = $templateService->activate($templateService->createVersion($template, '01/01/24', $admin));
        $retired = $templateService->retire($version, 'edition superseded');

        $draftService = new FormDraftGenerationService(
            new DeterministicFieldResolutionService(),
            new FormMissingDataDetectionService(),
        );

        $this->expectException(\RuntimeException::class);
        $draftService->generate($matter, $retired, $actor);
    }

    public function test_retiring_only_writes_to_the_form_template_version_row(): void
    {
        $source = file_get_contents(app_path('Services/FormTemplateService.php'));

        $this->assertMatchesRegularExpression('/function retire\(/', $source);

        // Extract just the retire() method body and confirm it never
        // touches form_drafts / form_draft_values directly.
        preg_match('/function retire\([^)]*\)[^{]*\{(.*?)\n    \}/s', $source, $matches);
        $body = $matches[1] ?? '';

        $this->assertStringNotContainsString('FormDraft::', $body);
        $this->assertStringNotContainsString('form_drafts', $body);
        $this->assertStringNotContainsString('form_draft_values', $body);
    }
}
