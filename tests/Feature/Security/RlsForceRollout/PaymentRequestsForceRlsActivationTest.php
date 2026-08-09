<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\FirmUserRole;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PaymentRequest;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaymentRequestsForceRlsActivationTest — Payment Link / QR Routing
 * phase. Proves payment_requests' permanent FORCE ROW LEVEL SECURITY
 * (2026_11_01_100002) behaves correctly: fail-closed with no context,
 * correct cross-firm isolation, and that ordinary firm-context writes
 * (status transitions via PaymentRequestService) keep working.
 *
 * The self-lookup carve-out (payment_requests_self_lookup,
 * 2026_11_01_100003) is proven separately by
 * tests/Feature/PaymentRequests/PaymentRequestRlsTest.php — this file
 * only proves the ordinary tenant_isolation policy.
 */
class PaymentRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_payment_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PaymentRequest::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_requests(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $creator = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_requests')->insert([
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'purpose' => 'earned_fee',
            'amount_rule' => 'fixed',
            'requested_amount_cents' => 1000,
            'currency' => 'usd',
            'status' => 'draft',
            'created_by_firm_user_id' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_requests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => PaymentRequest::factory()->forFirm($firmA)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => PaymentRequest::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentRequest::query()->pluck('id')->all(),
        );

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_to_payment_requests_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => $paymentRequest->update(['status' => 'active', 'activated_at' => now()]));

        $reRead = $this->runWithFirmContext($firm, fn () => $paymentRequest->fresh()->status->value);
        $this->assertSame('active', $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_01_100002_prepare_row_level_security_and_force_rls_on_payment_requests_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_requests'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
