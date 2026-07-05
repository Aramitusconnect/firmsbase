<?php

namespace Tests\Feature\Forms\FormTemplates;

use App\Enums\FormTemplateVersionStatus;
use App\Enums\ImmigrationFormCode;
use App\Models\PlatformAdmin;
use App\Services\FormTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormTemplateService();
    }

    public function test_register_form_code_accepts_an_approved_starter_code(): void
    {
        $template = $this->service->registerFormCode('I-130', 'Petition for Alien Relative');

        $this->assertSame('I-130', $template->form_code);
    }

    public function test_register_form_code_rejects_an_unapproved_code(): void
    {
        $this->expectException(\ValueError::class);
        $this->service->registerFormCode('X-999', 'Not a real form');
    }

    public function test_all_seven_approved_starter_codes_are_accepted(): void
    {
        foreach (ImmigrationFormCode::cases() as $code) {
            $template = $this->service->registerFormCode($code->value, "Form {$code->value}");
            $this->assertSame($code->value, $template->form_code);
        }
    }

    public function test_create_version_requires_a_platform_admin_actor(): void
    {
        $template = $this->service->registerFormCode('I-485', 'Application to Register Permanent Residence');
        $admin = PlatformAdmin::factory()->create();

        $version = $this->service->createVersion($template, '01/20/25', $admin);

        $this->assertSame($admin->id, $version->created_by_platform_admin_id);
        $this->assertSame(FormTemplateVersionStatus::Draft, $version->status);
    }

    public function test_retire_only_writes_to_the_version_itself(): void
    {
        $template = $this->service->registerFormCode('N-400', 'Application for Naturalization');
        $admin = PlatformAdmin::factory()->create();
        $version = $this->service->activate($this->service->createVersion($template, '01/01/24', $admin));

        $retired = $this->service->retire($version, 'superseded by newer edition');

        $this->assertSame(FormTemplateVersionStatus::Retired, $retired->status);
        $this->assertNotNull($retired->retired_at);
        $this->assertSame('superseded by newer edition', $retired->retired_reason);
    }
}
