<?php

namespace Database\Factories;

use App\Enums\DocumentRequestStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<DocumentRequest>
 */
class DocumentRequestFactory extends Factory
{
    protected $model = DocumentRequest::class;

    /**
     * Section 39A-3L — context-hold pattern (matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare DocumentRequest::factory()
     * ->create() works correctly even called from outside any already-
     * active tenant context. Deliberately does not clear context
     * afterward. created_by references the non-tenant users table, not
     * a tenant-owned parent, so there is no nested-record ownership
     * mismatch to fix here.
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
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * firm_id and client_id are derived from ONE authoritative Client —
     * mirrors forClient()'s pattern below.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Client::factory()->create() as a plain PHP statement at the top
     * of definition() — a real, committed Client every single time,
     * even when forClient() below immediately overrides both keys with
     * a caller-supplied client. Fixed by memoizing the client behind
     * lazy closures so nothing is created unless it survives,
     * unoverridden, to the final row.
     */
    private ?Client $lazyClient = null;

    public function definition(): array
    {
        $this->lazyClient = null;

        return [
            'firm_id' => function () {
                $this->lazyClient ??= Client::factory()->create();

                return $this->lazyClient->firm_id;
            },
            'matter_id' => null,
            'client_id' => function () {
                $this->lazyClient ??= Client::factory()->create();

                return $this->lazyClient->id;
            },
            'status' => DocumentRequestStatus::Open,
            'title' => 'Please provide the following documents',
            'instructions' => $this->faker->sentence(),
            'due_at' => now()->addDays(14),
            'created_by' => null,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => ['firm_id' => $client->firm_id, 'client_id' => $client->id]);
    }
}
