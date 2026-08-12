<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MarketplaceAiUsageEvent>
 */
class MarketplaceAiUsageEventFactory extends Factory
{
    protected $model = MarketplaceAiUsageEvent::class;

    /**
     * marketplace_ai_usage_events has permanent FORCE ROW LEVEL
     * SECURITY with a nullable-firm_id policy pair (mirrors
     * security_events exactly) — a null-firm_id row can only be
     * inserted with NO ambient firm context active at all, while a
     * firm-scoped row needs that exact firm's context. Groups by
     * firm_id like every other FORCE-RLS factory, but routes the
     * null-firm_id group through runWithoutFirmContext() instead of
     * setDatabaseTenantContextForFirmId().
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $firmId = $group->first()->firm_id;

            if ($firmId === null) {
                $service->runWithoutFirmContext(fn () => $this->store($group));

                return;
            }

            $service->setDatabaseTenantContextForFirmId($firmId);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'marketplace_intake_id' => null,
            'session_hash' => hash('sha1', $this->faker->uuid()),
            'ip_address' => $this->faker->ipv4(),
            'provider' => AiProvider::OpenAi,
            'model' => 'fake-model-1',
            'action_type' => AiUsageActionType::IntakeClassification,
            'tokens_in' => 10,
            'tokens_out' => 10,
        ];
    }
}
