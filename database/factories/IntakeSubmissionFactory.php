<?php

namespace Database\Factories;

use App\Enums\IntakeSubmissionStatus;
use App\Models\Client;
use App\Models\IntakeSubmission;
use App\Models\IntakeTemplate;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<IntakeSubmission>
 */
class IntakeSubmissionFactory extends Factory
{
    protected $model = IntakeSubmission::class;

    /**
     * Section 39A-3L, Checkpoint 13 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * IntakeSubmission::factory()->create() works correctly even called
     * from outside any already-active tenant context.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * firm_id and client_id used to be two independent random Factory
     * chains (the same bug class as Checkpoints 5/7/8/10/12): a bare
     * IntakeSubmission::factory()->create() could resolve a client
     * belonging to a DIFFERENT firm than the one written to firm_id.
     * Fixed here by creating one authoritative Client up front and
     * deriving both firm_id and client_id from it — mirrors
     * forClient()'s already-correct pattern below.
     */
    public function definition(): array
    {
        $client = Client::factory()->create();

        return [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
            'matter_id' => null,
            'intake_template_id' => IntakeTemplate::factory(),
            'status' => IntakeSubmissionStatus::Draft,
            'responses_json' => [],
            'submitted_at' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => ['firm_id' => $client->firm_id, 'client_id' => $client->id]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => IntakeSubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
