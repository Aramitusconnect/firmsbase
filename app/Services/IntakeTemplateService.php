<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntakeQuestionType;
use App\Models\IntakeTemplate;
use App\Models\IntakeTemplateQuestion;
use App\Models\PracticeArea;
use Illuminate\Support\Collection;

/**
 * IntakeTemplateService — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 3. The sole writer of intake_template_questions
 * rows and the sole resolver of "which deterministic template applies
 * to this practice area" / "are these responses valid against this
 * template." Mirrors FormFieldService's own create/list shape,
 * extended with the practice-area resolution and response validation
 * this checkpoint's eligibility-gated intake flow needs.
 *
 * Deliberately AI-free: every method here is pure, deterministic
 * lookup/validation over already-stored rows — this is what "the
 * deterministic form must remain fully functional with AI OFF" means
 * in code, not just in a config flag.
 */
class IntakeTemplateService
{
    /**
     * @throws \InvalidArgumentException if $questionType is not a
     *                                   recognized IntakeQuestionType case, if a Select-type question
     *                                   has no options, or if a question is made to depend on itself.
     */
    public function createQuestion(
        IntakeTemplate $template,
        string $questionCode,
        string $label,
        string $questionType,
        bool $isRequired = false,
        int $sortOrder = 0,
        ?string $helpText = null,
        ?array $options = null,
        ?string $dependsOnCode = null,
        ?string $dependsOnEquals = null,
    ): IntakeTemplateQuestion {
        $type = IntakeQuestionType::tryFrom($questionType);

        if ($type === null) {
            throw new \InvalidArgumentException("Unsupported intake question type: {$questionType}");
        }

        if ($type->requiresOptions() && ($options === null || $options === [])) {
            throw new \InvalidArgumentException("Question type {$type->value} requires a non-empty options list.");
        }

        if ($dependsOnCode !== null && $dependsOnCode === $questionCode) {
            throw new \InvalidArgumentException('A question cannot depend on itself.');
        }

        return IntakeTemplateQuestion::create([
            'intake_template_id' => $template->id,
            'question_code' => $questionCode,
            'label' => $label,
            'help_text' => $helpText,
            'question_type' => $type,
            'is_required' => $isRequired,
            'sort_order' => $sortOrder,
            'options_json' => $options,
            'depends_on_code' => $dependsOnCode,
            'depends_on_equals' => $dependsOnEquals,
        ]);
    }

    /**
     * @return Collection<int, IntakeTemplateQuestion>
     */
    public function questionsFor(IntakeTemplate $template): Collection
    {
        return IntakeTemplateQuestion::query()
            ->where('intake_template_id', $template->id)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Resolves the deterministic template a MyAttorney marketplace
     * visitor sees for a given practice area — an active template
     * whose practice_area_id matches exactly, deterministically
     * tie-broken by id when more than one exists; falls back to the
     * active platform-wide default (practice_area_id null) when no
     * practice-area-specific template exists (or when $practiceArea
     * itself is null, e.g. the visitor has not yet been classified
     * into one). Never returns an inactive template.
     */
    public function templateForPracticeArea(?PracticeArea $practiceArea): ?IntakeTemplate
    {
        if ($practiceArea !== null) {
            $specific = IntakeTemplate::query()
                ->where('is_active', true)
                ->where('practice_area_id', $practiceArea->id)
                ->orderBy('id')
                ->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        return IntakeTemplate::query()
            ->where('is_active', true)
            ->whereNull('practice_area_id')
            ->orderBy('id')
            ->first();
    }

    /**
     * Validates a submitted responses array against a template's real
     * question structure. Returns a map of question_code => error
     * message (empty array = valid). A conditionally-hidden question
     * (its depends_on_code answer does not match depends_on_equals)
     * is never required, regardless of its own is_required flag — the
     * server, not the browser, is what decides applicability.
     */
    public function validateResponses(IntakeTemplate $template, array $responses): array
    {
        $questions = $this->questionsFor($template);
        $errors = [];
        $knownCodes = $questions->pluck('question_code')->all();

        foreach (array_keys($responses) as $code) {
            if (! in_array($code, $knownCodes, true)) {
                $errors[$code] = 'This question does not exist on this template.';
            }
        }

        foreach ($questions as $question) {
            $code = $question->question_code;
            $value = $responses[$code] ?? null;

            if ($this->isConditionallyHidden($question, $responses)) {
                continue;
            }

            if ($question->is_required && ($value === null || $value === '')) {
                $errors[$code] = 'This field is required.';

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($question->question_type === IntakeQuestionType::Select) {
                $options = $question->options_json ?? [];

                if (! in_array($value, $options, true)) {
                    $errors[$code] = 'This value is not one of the allowed options.';
                }
            }
        }

        return $errors;
    }

    private function isConditionallyHidden(IntakeTemplateQuestion $question, array $responses): bool
    {
        if (! $question->isConditional()) {
            return false;
        }

        $controllingValue = $responses[$question->depends_on_code] ?? null;

        return (string) $controllingValue !== (string) $question->depends_on_equals;
    }
}
