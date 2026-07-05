<?php

namespace Tests\Feature\Forms\Mapping;

use App\Enums\FormMappingContentStatus;
use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\FormField;
use App\Models\PlatformAdmin;
use App\Services\FormMappingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormMappingRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_rule_defaults_to_sample_only(): void
    {
        $field = FormField::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $service = new FormMappingRuleService();

        $rule = $service->createRule(
            $field->formTemplateVersion,
            $field,
            FormMappingSourceEntity::Client,
            'client.display_name',
            FormMappingTransform::None,
            $admin
        );

        $this->assertSame(FormMappingContentStatus::SampleOnly, $rule->content_status);
        $this->assertSame($admin->id, $rule->created_by_platform_admin_id);
        $this->assertNull($rule->approved_by_platform_admin_id);
    }

    public function test_approve_content_requires_platform_admin_and_records_approver(): void
    {
        $field = FormField::factory()->create();
        $creator = PlatformAdmin::factory()->create();
        $approver = PlatformAdmin::factory()->create();
        $service = new FormMappingRuleService();

        $rule = $service->createRule(
            $field->formTemplateVersion,
            $field,
            FormMappingSourceEntity::Client,
            'client.display_name',
            FormMappingTransform::None,
            $creator
        );

        $approved = $service->approveContent($rule, $approver);

        $this->assertSame(FormMappingContentStatus::ReviewedApproved, $approved->content_status);
        $this->assertSame($approver->id, $approved->approved_by_platform_admin_id);
    }
}
