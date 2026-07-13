<?php

namespace Database\Factories;

use App\Enums\BackupRestoreTestStatus;
use App\Models\BackupRestoreTest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<BackupRestoreTest>
 */
class BackupRestoreTestFactory extends Factory
{
    protected $model = BackupRestoreTest::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'status' => BackupRestoreTestStatus::Passed,
            'components_verified_json' => ['database_records', 'documents', 'app_configuration', 'queues', 'tenant_settings', 'critical_logs'],
            'rpo_target_seconds' => 86400,
            'rto_target_seconds' => 28800,
            'rpo_actual_seconds' => 3600,
            'rto_actual_seconds' => 7200,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'notes' => 'Simulated drill.',
            'created_by' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => BackupRestoreTestStatus::Failed]);
    }

    public function missingTargets(): static
    {
        return $this->state(fn () => [
            'rpo_actual_seconds' => 100000,
            'rto_actual_seconds' => 40000,
        ]);
    }

    /**
     * Section 39A-3L Phase B6 — NOT a byte-identical copy of
     * ClientFactory/ContactFactory's create() override, because both of
     * those precedents only ever group rows with a real, non-null
     * firm_id (their definition() always resolves firm_id via
     * Firm::factory()), while this table's own default state is
     * firm_id = null. A verbatim copy would call
     * setDatabaseTenantContextForFirmId(null) for the default group and
     * throw a TypeError (the parameter is non-nullable int).
     *
     * The null-firm_id group explicitly calls
     * clearDatabaseTenantContext() before store() rather than assuming
     * absence of context — a direct, deliberate, unconditional clear
     * (not runWithoutFirmContext()'s save/restore wrapper), which is
     * correct here because a factory create() call is not nested inside
     * a caller-managed transaction boundary the way a service method
     * is; it is always the outermost tenant-context operation for the
     * row(s) it is producing at that moment. This matters because
     * ClientFactory/ContactFactory deliberately LEAVE app.current_firm_id
     * set after they run, so a null-firm_id BackupRestoreTest::factory()
     * ->create() running later in the same RefreshDatabase-scoped test
     * method, after some other tenant factory already ran, would still
     * have a stale non-null session setting active — which the
     * asymmetric WITH CHECK on backup_restore_tests_tenant_write would
     * then correctly reject as an RLS violation, not silently
     * mis-scope.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = app(TenantContextService::class);

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $firmId = $group->first()->firm_id;

            if ($firmId === null) {
                $service->clearDatabaseTenantContext();
                $this->store($group);

                return;
            }

            $service->setDatabaseTenantContextForFirmId($firmId);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }
}
