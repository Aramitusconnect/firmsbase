<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeAnswerService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\PracticeArea;
use App\Services\IntakeTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 6 —
 * MarketplaceIntakeAnswerService: the single writer of
 * MarketplaceIntake::structured_data, and the shared "what's next"
 * resolver both the deterministic and AI-assisted flows use.
 */
class MarketplaceIntakeAnswerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function answers(): MarketplaceIntakeAnswerService
    {
        return app(MarketplaceIntakeAnswerService::class);
    }

    private function templates(): IntakeTemplateService
    {
        return app(IntakeTemplateService::class);
    }

    /**
     * @return array{0: Firm, 1: MarketplaceIntake, 2: IntakeTemplate}
     */
    private function setUpFirmWithIntakeAndTemplate(): array
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $template = IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => true]);
        $this->templates()->createQuestion($template, 'legal_issue', 'Describe your issue', 'textarea', isRequired: true, sortOrder: 10);
        $this->templates()->createQuestion($template, 'state', 'Your state', 'text', isRequired: true, sortOrder: 20);

        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm, $practiceArea);

        return [$firm, $intake, $template];
    }

    public function test_starting_an_intake_auto_attaches_the_matching_deterministic_template(): void
    {
        [, $intake, $template] = $this->setUpFirmWithIntakeAndTemplate();

        $this->assertSame($template->id, $intake->intake_template_id);
    }

    public function test_next_question_returns_the_first_question_in_sort_order(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();

        $next = $this->answers()->nextQuestion($firm, $intake);

        $this->assertSame('legal_issue', $next->question_code);
    }

    public function test_save_answers_persists_a_valid_answer(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();

        $errors = $this->answers()->saveAnswers($firm, $intake, ['legal_issue' => 'Contract dispute with a vendor.']);

        $this->assertEmpty($errors);
        $fresh = $this->runWithFirmContext($firm, fn () => $intake->fresh());
        $this->assertSame('Contract dispute with a vendor.', $fresh->structured_data['legal_issue']);
    }

    public function test_save_answers_rejects_an_unknown_question_code(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();

        $errors = $this->answers()->saveAnswers($firm, $intake, ['not_a_real_field' => 'x']);

        $this->assertArrayHasKey('not_a_real_field', $errors);
    }

    public function test_next_question_advances_once_the_first_question_is_answered(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();
        $this->answers()->saveAnswers($firm, $intake, ['legal_issue' => 'Contract dispute.']);

        $next = $this->answers()->nextQuestion($firm, $intake);

        $this->assertSame('state', $next->question_code);
    }

    public function test_next_question_is_null_once_every_question_is_answered(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();
        $this->answers()->saveAnswers($firm, $intake, ['legal_issue' => 'Contract dispute.', 'state' => 'NY']);

        $this->assertNull($this->answers()->nextQuestion($firm, $intake));
    }

    public function test_the_full_deterministic_flow_completes_with_ai_untouched(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();

        $first = $this->answers()->nextQuestion($firm, $intake);
        $this->assertSame('legal_issue', $first->question_code);
        $this->answers()->saveAnswers($firm, $intake, [$first->question_code => 'Contract dispute.']);

        $second = $this->answers()->nextQuestion($firm, $intake);
        $this->assertSame('state', $second->question_code);
        $this->answers()->saveAnswers($firm, $intake, [$second->question_code => 'NY']);

        $this->assertNull($this->answers()->nextQuestion($firm, $intake));
        $fresh = $this->runWithFirmContext($firm, fn () => $intake->fresh());
        $this->assertFalse($fresh->ai_assisted);
    }

    public function test_append_transcript_entry_never_touches_structured_data(): void
    {
        [$firm, $intake] = $this->setUpFirmWithIntakeAndTemplate();
        $this->answers()->saveAnswers($firm, $intake, ['legal_issue' => 'Contract dispute.']);

        $this->answers()->appendTranscriptEntry($firm, $intake, 'visitor', 'Hello, I need help.');

        $fresh = $this->runWithFirmContext($firm, fn () => $intake->fresh());
        $this->assertCount(1, $fresh->conversation_transcript);
        $this->assertSame('Hello, I need help.', $fresh->conversation_transcript[0]['content']);
        $this->assertArrayNotHasKey('conversation_transcript', $fresh->structured_data ?? []);
        $this->assertSame('Contract dispute.', $fresh->structured_data['legal_issue']);
    }

    public function test_save_answers_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        [, $intake] = $this->setUpFirmWithIntakeAndTemplate();
        $otherFirm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->answers()->saveAnswers($otherFirm, $intake, ['legal_issue' => 'x']);
    }
}
