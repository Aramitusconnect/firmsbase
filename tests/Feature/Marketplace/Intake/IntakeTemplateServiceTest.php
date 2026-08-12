<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\AiMode;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\PracticeArea;
use App\Services\AiModeResolutionService;
use App\Services\IntakeTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 3 —
 * IntakeTemplateService: deterministic template resolution, question
 * ordering, response validation. Every test here proves the
 * deterministic form works with zero AI involvement.
 */
class IntakeTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntakeTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntakeTemplateService;
    }

    public function test_create_question_rejects_an_unsupported_question_type(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createQuestion($template, 'q1', 'Question one', 'not_a_real_type');
    }

    public function test_create_question_rejects_a_select_type_with_no_options(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createQuestion($template, 'q1', 'Pick one', 'select');
    }

    public function test_create_question_rejects_a_question_depending_on_itself(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createQuestion($template, 'q1', 'Question one', 'text', dependsOnCode: 'q1', dependsOnEquals: 'yes');
    }

    public function test_create_question_accepts_every_supported_type(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();

        foreach (['text', 'textarea', 'date', 'number', 'email', 'phone', 'checkbox'] as $type) {
            $question = $this->service->createQuestion($template, "q_{$type}", ucfirst($type), $type);
            $this->assertSame($type, $question->question_type->value);
        }

        $select = $this->service->createQuestion($template, 'q_select', 'Select', 'select', options: ['a', 'b']);
        $this->assertSame('select', $select->question_type->value);
    }

    public function test_template_for_practice_area_returns_a_matching_active_template(): void
    {
        $practiceArea = PracticeArea::factory()->create();
        $template = IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => true]);

        $resolved = $this->service->templateForPracticeArea($practiceArea);

        $this->assertNotNull($resolved);
        $this->assertSame($template->id, $resolved->id);
    }

    public function test_template_for_practice_area_excludes_an_inactive_template(): void
    {
        $practiceArea = PracticeArea::factory()->create();
        IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => false]);

        $resolved = $this->service->templateForPracticeArea($practiceArea);

        $this->assertNull($resolved);
    }

    public function test_template_for_practice_area_falls_back_to_the_platform_default_when_no_specific_template_exists(): void
    {
        $practiceArea = PracticeArea::factory()->create();
        $default = IntakeTemplate::factory()->marketplaceDefault()->create(['is_active' => true]);

        $resolved = $this->service->templateForPracticeArea($practiceArea);

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id);
    }

    public function test_template_for_practice_area_returns_null_when_nothing_active_exists_at_all(): void
    {
        $practiceArea = PracticeArea::factory()->create();

        $this->assertNull($this->service->templateForPracticeArea($practiceArea));
        $this->assertNull($this->service->templateForPracticeArea(null));
    }

    public function test_questions_for_returns_questions_ordered_by_sort_order_deterministically(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'third', 'Third', 'text', sortOrder: 30);
        $this->service->createQuestion($template, 'first', 'First', 'text', sortOrder: 10);
        $this->service->createQuestion($template, 'second', 'Second', 'text', sortOrder: 20);

        $codes = $this->service->questionsFor($template)->pluck('question_code')->all();

        $this->assertSame(['first', 'second', 'third'], $codes);
    }

    public function test_validate_responses_enforces_required_fields(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'name', 'Your name', 'text', isRequired: true);

        $errors = $this->service->validateResponses($template, []);

        $this->assertArrayHasKey('name', $errors);
    }

    public function test_validate_responses_passes_when_required_fields_are_present(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'name', 'Your name', 'text', isRequired: true);

        $errors = $this->service->validateResponses($template, ['name' => 'Jane Doe']);

        $this->assertEmpty($errors);
    }

    public function test_validate_responses_rejects_an_unknown_question_code(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'name', 'Your name', 'text');

        $errors = $this->service->validateResponses($template, ['name' => 'Jane', 'injected_field' => 'x']);

        $this->assertArrayHasKey('injected_field', $errors);
    }

    public function test_validate_responses_rejects_a_select_value_not_in_options(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'state', 'State', 'select', options: ['NY', 'CA']);

        $errors = $this->service->validateResponses($template, ['state' => 'ZZ']);

        $this->assertArrayHasKey('state', $errors);
    }

    public function test_validate_responses_never_requires_a_conditionally_hidden_question(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'has_prior_case', 'Have you had a prior case?', 'checkbox');
        $this->service->createQuestion($template, 'prior_case_number', 'Prior case number', 'text', isRequired: true, dependsOnCode: 'has_prior_case', dependsOnEquals: '1');

        // has_prior_case is not '1', so prior_case_number's own
        // is_required=true must be waived, not enforced.
        $errors = $this->service->validateResponses($template, ['has_prior_case' => '0']);

        $this->assertEmpty($errors);
    }

    public function test_validate_responses_requires_a_conditionally_shown_question(): void
    {
        $template = IntakeTemplate::factory()->marketplaceDefault()->create();
        $this->service->createQuestion($template, 'has_prior_case', 'Have you had a prior case?', 'checkbox');
        $this->service->createQuestion($template, 'prior_case_number', 'Prior case number', 'text', isRequired: true, dependsOnCode: 'has_prior_case', dependsOnEquals: '1');

        $errors = $this->service->validateResponses($template, ['has_prior_case' => '1']);

        $this->assertArrayHasKey('prior_case_number', $errors);
    }

    public function test_the_full_deterministic_flow_works_with_ai_disabled(): void
    {
        $firm = Firm::factory()->create();
        $this->assertSame(AiMode::Disabled, app(AiModeResolutionService::class)->resolve($firm));

        $practiceArea = PracticeArea::factory()->create();
        $template = IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => true]);
        $this->service->createQuestion($template, 'legal_issue', 'Describe your legal issue', 'textarea', isRequired: true, sortOrder: 10);

        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm, $practiceArea);

        $resolvedTemplate = $this->service->templateForPracticeArea($practiceArea);
        $this->assertSame($template->id, $resolvedTemplate?->id);

        $errors = $this->service->validateResponses($resolvedTemplate, ['legal_issue' => 'I need help with a contract dispute.']);

        $this->assertEmpty($errors);
        $this->assertSame($firm->id, $intake->firm_id);
    }
}
