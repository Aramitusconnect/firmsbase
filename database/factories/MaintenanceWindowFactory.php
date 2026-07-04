<?php

namespace Database\Factories;

use App\Enums\MaintenanceWindowStatus;
use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
