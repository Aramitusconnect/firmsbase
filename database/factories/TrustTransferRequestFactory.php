<?php

namespace Database\Factories;

use App\Enums\TrustTransferRequestStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TrustLedger;
use App\Models\TrustTransferRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustTransferRequest>
 */
class TrustTransferRequestFactory extends Factory
{
    protected $model = TrustTransferRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_ledger_id' => TrustLedger::factory(),
            'matter_id' => Matter::factory(),
            'invoice_id' => Invoice::factory(),
            'amount_cents' => 5000,
            'status' => TrustTransferRequestStatus::Requested,
            'requested_by_firm_user_id' => FirmUser::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => TrustTransferRequestStatus::Approved,
            'approved_by_firm_user_id' => FirmUser::factory(),
        ]);
    }
}
