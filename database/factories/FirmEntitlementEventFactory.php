<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\FirmEntitlementEvent;
use App\Models\ModuleCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmEntitlementEvent>
 */
class FirmEntitlementEventFactory extends Factory
{
    protected $model = FirmEntitlementEvent::class;

    public function definition(): array
    {
        return [
            'firm_entitlement_id' => FirmEntitlement::factory(),
            'firm_id' => Firm::factory(),
            'module_code' => fn () => ModuleCatalog::factory()->create()->module_code,
            'source' => 'admin_override',
            'action' => 'granted',
            'reason' => null,
            'actor_type' => 'System',
            'actor_id' => null,
            'metadata' => [],
        ];
    }

    public function forEntitlement(FirmEntitlement $entitlement): static
    {
        return $this->state(fn () => [
            'firm_entitlement_id' => $entitlement->id,
            'firm_id' => $entitlement->firm_id,
            'module_code' => $entitlement->module_code,
            'source' => $entitlement->source instanceof \BackedEnum
                ? $entitlement->source->value
                : $entitlement->source,
        ]);
    }
}
