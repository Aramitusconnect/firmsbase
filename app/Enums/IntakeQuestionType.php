<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * IntakeQuestionType — intake_template_questions.question_type.
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 3. A
 * deliberately small, fully deterministic vocabulary — mirrors
 * FormFieldType's own shape (see app/Enums/FormFieldType.php) rather
 * than inventing an unrelated one, extended with Email/Phone/Textarea
 * since those are genuine intake-question needs. No file-upload case
 * exists here: document collection goes through the real quarantine/
 * malware-scan pipeline, wired in checkpoint 7, never through this
 * deterministic-answer table. No AI-classified/open-ended-reasoning
 * type exists either — this enum, and the form it backs, must remain
 * fully functional with AI OFF.
 */
enum IntakeQuestionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Date = 'date';
    case Number = 'number';
    case Email = 'email';
    case Phone = 'phone';
    case Select = 'select';
    case Checkbox = 'checkbox';

    /**
     * Whether this type requires a non-empty options_json array of
     * choices — enforced by IntakeTemplateService::createQuestion().
     */
    public function requiresOptions(): bool
    {
        return $this === self::Select;
    }
}
