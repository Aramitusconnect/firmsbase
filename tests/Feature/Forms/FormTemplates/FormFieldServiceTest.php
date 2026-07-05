<?php

namespace Tests\Feature\Forms\FormTemplates;

use App\Enums\FormFieldType;
use App\Models\FormTemplateVersion;
use App\Services\FormFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormFieldServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_field_persists_expected_attributes(): void
    {
        $version = FormTemplateVersion::factory()->create();
        $service = new FormFieldService();

        $field = $service->createField($version, 'beneficiary_full_name', 'Beneficiary Full Name', FormFieldType::Text, true, 1);

        $this->assertSame('beneficiary_full_name', $field->field_code);
        $this->assertTrue($field->is_required);
        $this->assertSame(FormFieldType::Text, $field->field_type);
        $this->assertSame($version->id, $field->form_template_version_id);
    }

    public function test_list_fields_for_version_is_ordered_by_sort_order(): void
    {
        $version = FormTemplateVersion::factory()->create();
        $service = new FormFieldService();

        $service->createField($version, 'field_b', 'B', FormFieldType::Text, false, 2);
        $service->createField($version, 'field_a', 'A', FormFieldType::Text, false, 1);

        $fields = $service->listFieldsForVersion($version);

        $this->assertSame(['field_a', 'field_b'], $fields->pluck('field_code')->all());
    }
}
