<?php

namespace Database\Factories;

use App\Enums\TrustApprovalEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustApprovalEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrustApprovalEvent>
 */
class TrustApprovalEventFactory extends Factory
{
    protected $model = TrustApprovalEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'event_type' => TrustApprovalEventType::DepositRequested,
            'actor_firm_user_id' => FirmUser::factory(),
            'amount_cents' => 10000,
            'matter_id' => null,
            'approved_entry_type' => null,
            'correlation_uuid' => (string) Str::uuid7(),
            'trust_ledger_id' => null,
        ];
    }
}
