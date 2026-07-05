<?php

namespace Database\Factories;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Models\ExportJob;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    protected $model = ExportJob::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'export_type' => ExportType::Clients->value,
            'status' => ExportJobStatus::Requested->value,
            'legal_hold_checked' => true,
            'retention_checked' => true,
            'offboarding_checked' => true,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function type(ExportType $type): static
    {
        return $this->state(fn () => ['export_type' => $type->value]);
    }
}
