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
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The session and its own parent support_access_request are always
     * tied to the SAME firm — genuine bug fix, not just the create()
     * override above: this previously generated 'firm_id' =>
     * Firm::factory() AND 'support_access_request_id' =>
     * SupportAccessRequest::factory() as two fully INDEPENDENT
     * factories, which could (and, at low but real probability, would)
     * produce a session whose firm_id disagrees with its own parent
     * request's firm_id — exactly the masked-blast-radius risk
     * MatterFactory.php's own docblock warns about, and exactly the
     * class of gap support_access_sessions' composite foreign key
     * (firm_id, support_access_request_id) REFERENCES
     * support_access_requests(firm_id, id), added in this same batch's
     * 2026_08_28_960005 migration, would now reject outright as an
     * invalid insert. One authoritative SupportAccessRequest is created
     * up front and firm_id is derived directly from it instead.
     */
    public function definition(): array
    {
        $request = SupportAccessRequest::factory()->create();

        return [
            'support_access_request_id' => $request->id,
            'firm_id' => $request->firm_id,
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
