<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntakeQuestionType;
use Database\Factories\IntakeTemplateQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntakeTemplateQuestion — GLOBAL, child of IntakeTemplate. Mission 3
 * (MyAttorney Conversion + AI Intake), checkpoint 3. See the
 * create-table migration's own docblock for the full rationale.
 */
class IntakeTemplateQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'intake_template_id',
        'question_code',
        'label',
        'help_text',
        'question_type',
        'is_required',
        'sort_order',
        'options_json',
        'depends_on_code',
        'depends_on_equals',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => IntakeQuestionType::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'options_json' => 'array',
        ];
    }

    protected static function newFactory(): IntakeTemplateQuestionFactory
    {
        return IntakeTemplateQuestionFactory::new();
    }

    public function intakeTemplate(): BelongsTo
    {
        return $this->belongsTo(IntakeTemplate::class);
    }

    /**
     * Whether this question is conditionally gated on another
     * question's answer — false for the common, unconditional case.
     */
    public function isConditional(): bool
    {
        return $this->depends_on_code !== null;
    }
}
