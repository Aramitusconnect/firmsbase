<?php

namespace Tests\Feature\Forms\Drafts;

use App\Enums\FormDraftValueSource;
use App\Models\FormDraft;
use App\Models\FormDraftValue;
use App\Models\FormField;
use App\Models\FormMissingDataItem;
use App\Services\FormMissingDataDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormMissingDataDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormMissingDataDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormMissingDataDetectionService();
    }

    public function test_scan_flags_a_missing_required_field(): void
    {
        $draft = FormDraft::factory()->create();
        $field = FormField::factory()->required()->forVersion($draft->formTemplateVersion)->create();
        FormDraftValue::factory()->forDraft($draft)->create([
            'form_field_id' => $field->id,
            'value' => null,
            'source' => FormDraftValueSource::Missing->value,
        ]);

        $result = $this->service->scan($draft);

        $this->assertFalse($result->isComplete());
        $this->assertContains($field->field_code, $result->missingFieldCodes);
        $this->assertDatabaseHas('form_missing_data_items', [
            'form_draft_id' => $draft->id,
            'form_field_id' => $field->id,
        ]);
    }

    public function test_scan_is_complete_when_all_required_fields_are_populated(): void
    {
        $draft = FormDraft::factory()->create();
        $field = FormField::factory()->required()->forVersion($draft->formTemplateVersion)->create();
        FormDraftValue::factory()->forDraft($draft)->create([
            'form_field_id' => $field->id,
            'value' => 'Present',
            'source' => FormDraftValueSource::ManualOverride->value,
        ]);

        $result = $this->service->scan($draft);

        $this->assertTrue($result->isComplete());
        $this->assertSame([], $result->missingFieldCodes);
    }

    public function test_rescan_resolves_a_previously_missing_item_once_populated(): void
    {
        $draft = FormDraft::factory()->create();
        $field = FormField::factory()->required()->forVersion($draft->formTemplateVersion)->create();
        $value = FormDraftValue::factory()->forDraft($draft)->create([
            'form_field_id' => $field->id,
            'value' => null,
            'source' => FormDraftValueSource::Missing->value,
        ]);

        $this->service->scan($draft);
        $this->assertDatabaseHas('form_missing_data_items', ['form_field_id' => $field->id, 'resolved_at' => null]);

        $value->update(['value' => 'Now present', 'source' => FormDraftValueSource::ManualOverride->value]);
        $this->service->scan($draft->fresh());

        $item = FormMissingDataItem::query()->where('form_field_id', $field->id)->first();
        $this->assertNotNull($item->resolved_at);
    }
}
