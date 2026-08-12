<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntakeQuestionType;
use App\Models\IntakeTemplate;
use App\Models\IntakeTemplateQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeTemplateQuestion>
 */
class IntakeTemplateQuestionFactory extends Factory
{
    protected $model = IntakeTemplateQuestion::class;

    public function definition(): array
    {
        return [
            'intake_template_id' => IntakeTemplate::factory(),
            'question_code' => $this->faker->unique()->slug(2, false),
            'label' => $this->faker->sentence(4),
            'help_text' => null,
            'question_type' => IntakeQuestionType::Text,
            'is_required' => false,
            'sort_order' => 0,
            'options_json' => null,
            'depends_on_code' => null,
            'depends_on_equals' => null,
        ];
    }

    public function forTemplate(IntakeTemplate $template): static
    {
        return $this->state(fn () => ['intake_template_id' => $template->id]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function type(IntakeQuestionType $type): static
    {
        return $this->state(fn () => ['question_type' => $type]);
    }
}
