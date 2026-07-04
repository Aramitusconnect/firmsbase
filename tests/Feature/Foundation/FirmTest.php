<?php

namespace Tests\Feature\Foundation;

use App\Enums\FirmActivationStatus;
use App\Models\BillingAccount;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_without_billing_account(): void
    {
        $firm = Firm::factory()->create();

        $this->assertNull($firm->billing_account_id);
        $this->assertSame(FirmActivationStatus::Draft, $firm->activation_status);
    }

    public function test_it_can_be_created_with_billing_account(): void
    {
        $billingAccount = BillingAccount::factory()->create();
        $firm = Firm::factory()->withBillingAccount($billingAccount)->create();

        $this->assertSame($billingAccount->id, $firm->billing_account_id);
    }

    public function test_activation_status_only_has_three_values(): void
    {
        $draft = Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft]);
        $onboarding = Firm::factory()->create(['activation_status' => FirmActivationStatus::Onboarding]);
        $activated = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->assertSame(FirmActivationStatus::Draft, $draft->activation_status);
        $this->assertSame(FirmActivationStatus::Onboarding, $onboarding->activation_status);
        $this->assertSame(FirmActivationStatus::Activated, $activated->activation_status);
        $this->assertCount(3, FirmActivationStatus::cases());
    }

    public function test_no_suspended_or_archived_values_exist(): void
    {
        $values = array_map(fn ($case) => $case->value, FirmActivationStatus::cases());

        $this->assertNotContains('suspended', $values);
        $this->assertNotContains('archived', $values);
    }
}
