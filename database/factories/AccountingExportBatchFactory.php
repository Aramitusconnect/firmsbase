<?php

namespace Database\Factories;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportTarget;
use App\Models\AccountingExportBatch;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingExportBatch>
 */
class AccountingExportBatchFactory extends Factory
{
    protected $model = AccountingExportBatch::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'export_target' => AccountingExportTarget::QuickbooksOnline,
            'status' => AccountingExportBatchStatus::Requested,
            'requested_by_firm_user_id' => FirmUser::factory(),
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
