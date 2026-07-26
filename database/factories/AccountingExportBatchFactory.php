<?php

namespace Database\Factories;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportTarget;
use App\Models\AccountingExportBatch;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AccountingExportBatch>
 */
class AccountingExportBatchFactory extends Factory
{
    protected $model = AccountingExportBatch::class;

    /**
     * accounting_export_batches has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_27_950023_prepare_row_level_
     * security_and_force_rls_on_accounting_export_batches_table.php),
     * so every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterExpenseFactory::create()'s
     * docblock for the full rationale.
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
     * The batch and its nested requested-by user are always tied to
     * the SAME firm. Audit fix (eager-factory-side-effects audit): this
     * used to call Firm::factory()->create() as a plain PHP statement
     * at the top of definition() — a real, committed Firm every single
     * time, even when forFirm() below immediately overrides firm_id
     * (and requested_by_firm_user_id) with a caller-supplied firm.
     * Laravel cannot skip a side effect that already happened while
     * building the array; it can only skip re-resolving a definition()
     * value that is still an unresolved Factory/Closure by the time a
     * later state() overrides that key. Every forFirm()-scoped create()
     * was therefore silently wasting one real, fully-committed Firm per
     * call. Fixed by making firm_id Laravel's own lazy
     * factory-relationship form and deriving requested_by_firm_user_id
     * from the already-resolved firm_id via a lazy closure — nothing is
     * created unless it survives, unoverridden, to the final row.
     * Mirrors FirmIntegrationFactory's established fix shape.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'export_target' => AccountingExportTarget::QuickbooksOnline,
            'status' => AccountingExportBatchStatus::Requested,
            'requested_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
            'date_range_start' => now()->subDays(30),
            'date_range_end' => now(),
            'started_at' => null,
            'completed_at' => null,
            'failed_reason' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'requested_by_firm_user_id' => FirmUser::factory()->forFirm($firm),
        ]);
    }

    public function status(AccountingExportBatchStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
