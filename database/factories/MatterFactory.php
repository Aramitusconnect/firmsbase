<?php

namespace Database\Factories;

use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    protected $model = Matter::class;

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
     * The matter and its nested client are always tied to the SAME
     * firm — generating one firm here up front (rather than letting
     * firm_id and client_id resolve as two independent
     * Firm::factory()/Client::factory() calls) is deliberate: a bare
     * Matter::factory()->create() with no state must never produce a
     * matter whose client belongs to an unrelated firm, since that
     * mismatch is exactly the masked-blast-radius risk this table was
     * deferred for in earlier RLS FORCE batches.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();

        return [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
            'primary_practice_area_id' => $practiceArea->id,
            'matter_type_id' => MatterType::factory()->forPracticeArea($practiceArea),
            'pinned_template_pack_version_id' => null,
            'status' => MatterStatus::Draft,
            'stage' => null,
            'assigned_attorney_id' => null,
            'opened_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Ties both the matter AND its nested client to the given firm —
     * used when the caller already has a specific pre-existing firm to
     * attach to, rather than the fresh random one definition() would
     * otherwise generate.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => ['firm_id' => $client->firm_id, 'client_id' => $client->id]);
    }

    public function status(MatterStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
