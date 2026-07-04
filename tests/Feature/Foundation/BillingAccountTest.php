<?php

namespace Tests\Feature\Foundation;

use App\Models\BillingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $account = BillingAccount::factory()->create();

        $this->assertDatabaseHas('billing_accounts', ['id' => $account->id]);
    }

    public function test_no_payment_method_ref_column_exists(): void
    {
        $account = BillingAccount::factory()->create();

        $this->assertArrayNotHasKey('payment_method_ref', $account->getAttributes());
    }
}
