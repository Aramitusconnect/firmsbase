<?php

namespace Database\Factories;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmUser>
 */
class FirmUserFactory extends Factory
{
    protected $model = FirmUser::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'firm_id' => Firm::factory(),
            'role' => FirmUserRole::Attorney,
            'status' => FirmUserStatus::Active,
            'is_primary' => false,
            'invited_by' => null,
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function role(FirmUserRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    /**
     * Section 39A-3B — firm_users has FORCE ROW LEVEL SECURITY active,
     * so every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. Same pattern as ClientFactory
     * (Section 39A-3A): reads the firm_id the factory itself already
     * resolved, sets the PostgreSQL session setting only (never
     * PHP-memory TenantContextResolver state, which BelongsToTenant's
     * global scope reads — leaving that active would leak an implicit
     * firm_id constraint into unrelated queries for the rest of the
     * test), and deliberately leaves it set afterward for the common
     * "create then read" test pattern.
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
}
