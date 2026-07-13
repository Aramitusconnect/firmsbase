<?php

namespace Database\Factories;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use App\Models\Firm;
use App\Models\PilotFeedbackItem;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PilotFeedbackItem>
 */
class PilotFeedbackItemFactory extends Factory
{
    protected $model = PilotFeedbackItem::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => null,
            'matter_id' => null,
            'user_id' => null,
            'source' => PilotFeedbackSource::Firm,
            'category' => PilotFeedbackCategory::UsabilityIssue,
            'priority' => PilotFeedbackPriority::Medium,
            'status' => PilotFeedbackStatus::New,
            'title' => 'Sample pilot feedback',
            'description' => 'The document upload button was hard to find.',
            'resolution_notes' => null,
            'follow_up_required' => false,
            'follow_up_at' => null,
            'resolved_at' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['firm_id' => null, 'source' => PilotFeedbackSource::Internal]);
    }

    /**
     * Section 39A-3L Phase B6 — same fix as the five prior tables'
     * factories: this table's default state is firm_id = Firm::factory()
     * (non-null, the inverse of every prior table's null-by-default
     * factory), so the firm-scoped group explicitly calls
     * setDatabaseTenantContextForFirmId() while the null-firm_id group
     * (e.g. internal()) calls clearDatabaseTenantContext(). The grouping
     * logic is symmetric and handles both defaults correctly regardless
     * of which one happens to be this table's default.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = app(TenantContextService::class);

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $firmId = $group->first()->firm_id;

            if ($firmId === null) {
                $service->clearDatabaseTenantContext();
                $this->store($group);

                return;
            }

            $service->setDatabaseTenantContextForFirmId($firmId);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }
}
