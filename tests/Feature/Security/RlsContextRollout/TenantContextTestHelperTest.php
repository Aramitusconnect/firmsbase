<?php

namespace Tests\Feature\Security\RlsContextRollout;

use App\Models\Client;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantContextTestHelperTest — Section 39A-2. Proves the new
 * tests/TestCase.php helper methods (runWithFirmContext(),
 * createWithFirmContext(), assertNoDatabaseTenantContext(),
 * assertDatabaseTenantContextIs()) correctly set and clear the real
 * PostgreSQL app.current_firm_id setting — including on exception —
 * with no second tenant-context mechanism introduced (they delegate
 * directly to the existing TenantContextService from Section 39A).
 */
class TenantContextTestHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_helper_sets_app_current_firm_id_correctly(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseTenantContextIs($firm);

            $value = DB::selectOne("select current_setting('app.current_firm_id', true) as value")->value;
            $this->assertSame((string) $firm->id, $value);
        });
    }

    public function test_helper_clears_app_current_firm_id_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => null);

        $this->assertNoDatabaseTenantContext();
    }

    public function test_helper_clears_app_current_firm_id_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('deliberate test failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    public function test_assert_no_database_tenant_context_fails_when_context_is_active(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\PHPUnit\Framework\ExpectationFailedException::class);

        $this->runWithFirmContext($firm, function () {
            $this->assertNoDatabaseTenantContext();
        });
    }

    public function test_assert_database_tenant_context_is_fails_for_the_wrong_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectException(\PHPUnit\Framework\ExpectationFailedException::class);

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            $this->assertDatabaseTenantContextIs($firmB);
        });
    }

    public function test_create_with_firm_context_is_the_same_mechanism_as_run_with_firm_context(): void
    {
        $firm = Firm::factory()->create();

        $client = $this->createWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseTenantContextIs($firm);

            return Client::factory()->forFirm($firm)->create();
        });

        $this->assertNotNull($client);
        $this->assertSame($firm->id, $client->firm_id);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_helpers_do_not_leak_context_between_two_sequential_calls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, function () use ($firmA) {
            $this->assertDatabaseTenantContextIs($firmA);
        });

        $this->runWithFirmContext($firmB, function () use ($firmB) {
            $this->assertDatabaseTenantContextIs($firmB);
        });

        $this->assertNoDatabaseTenantContext();
    }
}
