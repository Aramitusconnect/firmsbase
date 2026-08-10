<?php

namespace Database\Factories;

use App\Enums\MatterLeverageConfidence;
use App\Enums\MatterLeverageRecommendationStatus;
use App\Enums\MatterLeverageRecommendationType;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterLeverageRecommendation;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterLeverageRecommendation>
 */
class MatterLeverageRecommendationFactory extends Factory
{
    protected $model = MatterLeverageRecommendation::class;

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => Matter::factory(),
            'recommendation_type' => MatterLeverageRecommendationType::TaskRoleMismatch,
            'dedup_key' => 'document_follow_up',
            'confidence' => MatterLeverageConfidence::High,
            'status' => MatterLeverageRecommendationStatus::Open,
            'evidence_json' => [],
            'domain_event_id' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }

    public function status(MatterLeverageRecommendationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
