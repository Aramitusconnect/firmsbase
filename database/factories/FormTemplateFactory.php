<?php

namespace Database\Factories;

use App\Enums\FormTemplateStatus;
use App\Enums\ImmigrationFormCode;
use App\Models\FormTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplate>
 */
class FormTemplateFactory extends Factory
{
    protected $model = FormTemplate::class;

    public function definition(): array
    {
        return [
            'form_code' => $this->faker->unique()->randomElement(array_map(fn ($c) => $c->value, ImmigrationFormCode::cases())),
            'form_name' => $this->faker->words(3, true),
            'status' => FormTemplateStatus::Active->value,
        ];
    }

    public function code(ImmigrationFormCode $code): static
    {
        return $this->state(fn () => ['form_code' => $code->value]);
    }
}
