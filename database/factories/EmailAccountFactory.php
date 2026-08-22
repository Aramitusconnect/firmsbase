<?php

namespace Database\Factories;

use App\Enums\EmailAccountConnectionStatus;
use App\Enums\EmailProvider;
use App\Enums\EmailStorageMode;
use App\Models\EmailAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    protected $model = EmailAccount::class;

    /**
     * email_accounts has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950025_prepare_row_level_security_
     * and_force_rls_on_email_accounts_table.php), so every INSERT (test
     * or app) must run under the row's own app.current_firm_id context.
     * This bare (default) creation path is already tenant-consistent
     * (definition()'s connected_by_firm_user_id is a lazy closure that
     * reads the already-resolved firm_id, so the two columns can never
     * disagree), so this override adds ONLY the generic context-hold —
     * no root-cause definition() fix is needed. Mirrors
     * MatterExpenseFactory::create() exactly, including deliberately
     * leaving the database tenant context active afterward rather than
     * clearing it.
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

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'provider' => EmailProvider::Gmail->value,
            'mailbox_address' => $this->faker->unique()->safeEmail(),
            'connection_status' => EmailAccountConnectionStatus::Connected->value,
            'storage_mode' => EmailStorageMode::Disabled->value,
            // Resolved lazily so the created FirmUser belongs to the SAME
            // firm as this account — firm_id above is already a real,
            // persisted id by the time this closure runs.
            'connected_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
        ];
    }

    /**
     * Overrides BOTH firm_id and connected_by_firm_user_id together
     * (rather than firm_id alone) so a caller can never end up with a
     * fixture where the account's firm_id and its connecting FirmUser's
     * firm_id disagree — state application order relative to
     * definition()'s own lazy closures is not something this factory
     * relies on for correctness.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'connected_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function withStorageMode(EmailStorageMode $mode): static
    {
        return $this->state(fn () => ['storage_mode' => $mode->value]);
    }
}
