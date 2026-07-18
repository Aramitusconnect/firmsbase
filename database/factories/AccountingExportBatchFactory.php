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

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The batch and its nested requested-by user are always tied to
     * the SAME firm — one authoritative firm is generated up front
     * (rather than letting firm_id and requested_by_firm_user_id
     * resolve as two independent Firm::factory() calls), matching this
     * factory's own forFirm()'s existing shape and the root-cause fix
     * already applied to ExpenseFactory/MatterExpenseFactory.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'export_target' => AccountingExportTarget::QuickbooksOnline,
            'status' => AccountingExportBatchStatus::Requested,
            'requested_by_firm_user_id' => FirmUser::factory()->forFirm($firm),
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
