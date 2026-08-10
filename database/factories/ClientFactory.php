<?php

namespace Database\Factories;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'communication_preferences_id' => null,
            'display_name' => $this->faker->name(),
            'legal_name' => null,
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'preferred_language' => 'en',
            'preferred_timezone' => 'America/New_York',
            'portal_status' => ClientPortalStatus::NotInvited,
            'portal_invitation_token' => null,
            'portal_invitation_sent_at' => null,
            'portal_invitation_accepted_at' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    /**
     * Mission 1 (Domain & Security Boundary Architecture) — a Client
     * that has actually completed portal invitation acceptance and has
     * a real, login-capable password, exactly the two conditions
     * Client::canAccessPanel() requires. Mirrors what
     * ClientPortalService::acceptInvitation() would leave behind, for
     * tests that need a genuinely authenticatable Client without
     * exercising the full invite/accept flow themselves.
     */
    public function activeOnPortal(string $password = 'password'): static
    {
        return $this->state(fn () => [
            'portal_status' => ClientPortalStatus::Active,
            'portal_invitation_token' => null,
            'portal_invitation_accepted_at' => now(),
            'password' => $password,
        ]);
    }

    /**
     * Section 39A-3A — clients has FORCE ROW LEVEL SECURITY active, so
     * every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. firm_id is always fully resolved by
     * the time make() returns (it's never left as a nested Factory
     * value — see the parent definition() and forFirm() above), so
     * this reads the value the factory itself already decided for the
     * row being created; it does not infer tenant context from
     * anything caller/request-controlled. Rows are grouped by their
     * resolved firm_id so a single create()/count(n)->create() call
     * that legitimately spans more than one firm still activates the
     * correct context per row.
     *
     * Uses setDatabaseTenantContextForFirmId() — the PostgreSQL
     * session setting only — rather than setFirmContext()/
     * runWithFirmContext(), and deliberately never clears it
     * afterward. Tests throughout this suite overwhelmingly create a
     * client and then immediately read/assert on it (assertDatabaseHas,
     * relationship access, ->fresh()) with no wrapping context of
     * their own, so the RLS-visible setting needs to outlive this
     * call. Using the PHP-memory-touching setFirmContext() for that
     * instead was tried and reverted: TenantContextResolver's PHP
     * state is also what BelongsToTenant's global scope reads, so
     * leaving THAT active bled an implicit firm_id constraint into
     * every other tenant-owned model's queries for the rest of the
     * test — a much bigger behavior change than "let this table's RLS
     * policy see the row it just wrote."
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
}
