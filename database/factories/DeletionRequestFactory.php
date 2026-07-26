<?php

namespace Database\Factories;

use App\Enums\DeletionRequestStatus;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<DeletionRequest>
 *
 * Defaults to targeting a Matter as the subject, since deletion
 * governance may target many record types over time (approved
 * decision #9).
 */
class DeletionRequestFactory extends Factory
{
    protected $model = DeletionRequest::class;

    /**
     * deletion_requests has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_28_960002_prepare_row_level_security_
     * and_force_rls_on_deletion_requests_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterFactory::create()'s docblock for the full
     * rationale, including why setDatabaseTenantContextForFirmId() is
     * used instead of setFirmContext()/runWithFirmContext() and why the
     * setting is deliberately left active rather than cleared.
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
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Matter::factory()->create() as a plain PHP statement at the top
     * of definition() — a real, committed Matter (+ its own nested
     * Firm/Client) every single time, even when a real caller (see
     * DeletionRequestsForceRlsActivationTest::createRequestForFirm())
     * overrides firm_id/subject_id together via
     * DeletionRequest::factory()->create([...]), which this factory's
     * own create() override routes through $this->state($attributes).
     * Fixed by memoizing the matter behind lazy closures so nothing is
     * created unless firm_id/subject_id survive, unoverridden, to the
     * final row. subject_snapshot_json deliberately reads
     * $attributes['subject_id'] (already resolved by this point, since
     * it appears earlier in the array) rather than the memoized
     * $lazyMatter directly — the real call site
     * (DeletionRequestsForceRlsActivationTest::createRequestForFirm())
     * overrides subject_id but NOT subject_snapshot_json, so deriving
     * from $lazyMatter directly would independently trigger another
     * wasted Matter::factory()->create() in that case (lazyMatter is
     * never populated when firm_id/subject_id are both overridden).
     * Reading subject_id from $attributes both avoids that waste and
     * guarantees subject_snapshot_json's matter_id always agrees with
     * subject_id, regardless of which path produced it.
     */
    private ?Matter $lazyMatter = null;

    public function definition(): array
    {
        $this->lazyMatter = null;

        return [
            'firm_id' => function () {
                $this->lazyMatter ??= Matter::factory()->create();

                return $this->lazyMatter->firm_id;
            },
            'subject_type' => Matter::class,
            'subject_id' => function () {
                $this->lazyMatter ??= Matter::factory()->create();

                return $this->lazyMatter->id;
            },
            'subject_snapshot_json' => fn (array $attributes) => ['matter_id' => $attributes['subject_id']],
            'reason' => 'Platform admin requested governed hard delete after retention expiry.',
            'status' => DeletionRequestStatus::Requested,
            'requested_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
