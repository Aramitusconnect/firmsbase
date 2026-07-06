<?php

namespace Database\Factories;

use App\Enums\AiRetrievalIndexStatus;
use App\Models\AiRetrievalIndex;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiRetrievalIndex>
 */
class AiRetrievalIndexFactory extends Factory
{
    protected $model = AiRetrievalIndex::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'namespace_identifier' => 'firm-ns-'.(string) Str::uuid7(),
            'status' => AiRetrievalIndexStatus::Provisioned,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
