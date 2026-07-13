<?php

namespace Database\Factories;

use App\Enums\MaintenanceWindowStatus;
use App\Models\MaintenanceWindow;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'title' => 'Scheduled database maintenance',
            'status' => MaintenanceWindowStatus::Scheduled,
            'scheduled_starts_at' => now()->addDays(3),
            'scheduled_ends_at' => now()->addDays(3)->addHours(2),
            'actual_starts_at' => null,
            'actual_ends_at' => null,
            'affected_components' => ['database'],
            'public_message' => 'We will be performing scheduled maintenance.',
            'private_message' => null,
            'customer_notification_sent_at' => null,
            'rescheduled_from_id' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'created_by' => null,
        ];
    }

    /**
     * Section 39A-3L Phase B6 — same fix as BackupRestoreTestFactory
     * (see its own create() docblock for the full rationale): this
     * table's default state is firm_id = null, so the null-firm_id
     * group explicitly calls clearDatabaseTenantContext() rather than
     * setDatabaseTenantContextForFirmId(null), which would throw
     * (the parameter is non-nullable int).
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
