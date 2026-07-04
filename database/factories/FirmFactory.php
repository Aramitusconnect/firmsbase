<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmActivationStatus;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Firm>
 */
class FirmFactory extends Factory
{
    protected $model = Firm::class;

    public function definition(): array
    {
        return [
            // Both nullable by default — a firm in the draft/onboarding
            // stage does not require an organization or billing account.
            'organization_id' => null,
            'billing_account_id' => null,
            'name' => $this->faker->company().' Law Firm',
            'legal_name' => null,
            'customer_type' => CustomerType::LawFirm,
            'deployment_mode' => DeploymentMode::Saas,
            'primary_country' => 'US',
            'primary_state' => $this->faker->stateAbbr(),
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'data_region' => null,
            'activation_status' => FirmActivationStatus::Draft,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }

    public function withBillingAccount(?BillingAccount $billingAccount = null): static
    {
        return $this->state(fn () => [
            'billing_account_id' => ($billingAccount ?? BillingAccount::factory()->create())->id,
        ]);
    }

    public function activated(): static
    {
        return $this->state(fn () => ['activation_status' => FirmActivationStatus::Activated]);
    }
}
