<?php

namespace Database\Factories;

use App\Enums\TrustRefundRequestStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustLedger;
use App\Models\TrustRefundRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustRefundRequest>
 */
class TrustRefundRequestFactory extends Factory
{
    protected $model = TrustRefundRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_ledger_id' => TrustLedger::factory(),
            'matter_id' => null,
            'amount_cents' => 2500,
            'status' => TrustRefundRequestStatus::Requested,
            'requested_by_firm_user_id' => FirmUser::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => TrustRefundRequestStatus::Approved,
            'approved_by_firm_user_id' => FirmUser::factory(),
        ]);
    }
}
