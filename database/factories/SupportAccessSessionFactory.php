<?php

namespace Database\Factories;

use App\Enums\SupportAccessSessionStatus;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SupportAccessSession>
 */
class SupportAccessSessionFactory extends Factory
{
    protected $model = SupportAccessSession::class;

    /**
     * support_access_sessions has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_28_960005_prepare_row_level_
     * security_and_force_rls_on_support_access_sessions_table.php), so
     * every INSERT (test or app) must run under the row's own
     * app.current_firm_id context, despite this model not using
     * BelongsToTenant — RLS operates at the DB layer regardless of
     * Eloquent trait usage. See MatterFactory::create()'s docblock for
     * the full rationale, including why
     * setDatabaseTenantContextForFirmId() is used instead of
     * setFirmContext()/runWithFirmContext() and why the setting is
     * deliberately left active rather than cleared.
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
     * The session and its own parent support_access_request are always
     * tied to the SAME firm.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * SupportAccessRequest::factory()->create() as a plain PHP
     * statement at the top of definition() — a real, committed
     * SupportAccessRequest (+ its own nested Firm) every single time,
     * even when a real caller overrides support_access_request_id/
     * firm_id together via SupportAccessSession::factory()->create([...])
     * (this factory's own create() override routes attribute overrides
     * through $this->state($attributes)) — the pattern used by at least
     * six real test files, e.g.
     * SupportAccessSessionsForceRlsActivationTest::createSessionForFirm(),
     * SupportAccessSessionServiceTest, PlatformIntegrationAdminUiSecretSafetyTest.
     * Fixed by memoizing the request behind lazy closures so nothing is
     * created unless it survives, unoverridden, to the final row.
     */
    private ?SupportAccessRequest $lazyRequest = null;

    public function definition(): array
    {
        $this->lazyRequest = null;

        return [
            'support_access_request_id' => function () {
                $this->lazyRequest ??= SupportAccessRequest::factory()->create();

                return $this->lazyRequest->id;
            },
            'firm_id' => function () {
                $this->lazyRequest ??= SupportAccessRequest::factory()->create();

                return $this->lazyRequest->firm_id;
            },
            'platform_admin_id' => PlatformAdmin::factory(),
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SupportAccessSessionStatus::Expired->value,
            'started_at' => now()->subHours(3),
            'expires_at' => now()->subHour(),
        ]);
    }
}
