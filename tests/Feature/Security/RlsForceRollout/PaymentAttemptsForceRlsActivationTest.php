<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\BuildsPayFixtures;
use Tests\TestCase;

/**
 * PaymentAttemptsForceRlsActivationTest — proves the FirmsVault Pay Gate A2
 * table `payment_attempts` carries permanent FORCE ROW LEVEL SECURITY and behaves
 * correctly: fail-closed with no tenant context, and correct cross-firm
 * isolation.
 *
 * Follows the established RlsForceRollout convention (see
 * PaymentAllocationsForceRlsActivationTest) and satisfies
 * SchemaTenantFirewallTest check 5, which requires every forced table to
 * have a matching activation test file.
 */
class PaymentAttemptsForceRlsActivationTest extends TestCase
{
    use BuildsPayFixtures, RefreshDatabase;

    private const TABLE = 'payment_attempts';

    public function test_payment_attempts_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [self::TABLE]
        );

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_payment_attempts_policy_constrains_both_reads_and_writes(): void
    {
        $policy = DB::selectOne(
            'select qual, with_check from pg_policies where tablename = ?',
            [self::TABLE]
        );

        $this->assertNotNull($policy, self::TABLE.' must carry a tenant isolation policy.');
        $this->assertStringContainsString('app.current_firm_id', (string) $policy->qual);
        $this->assertStringContainsString(
            'app.current_firm_id',
            (string) $policy->with_check,
            self::TABLE.' must constrain writes as well as reads.'
        );
    }

    public function test_missing_tenant_context_cannot_read_payment_attempts(): void
    {
        $firm = Firm::factory()->create();
        $this->seedPayRowFor($firm, self::TABLE);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_attempts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->seedPayRowFor($firmB, self::TABLE);

        $visible = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table(self::TABLE)->count(),
        );

        $this->assertSame(0, $visible, 'Firm A must never see Firm B rows in '.self::TABLE.'.');

        $ownVisible = $this->runWithFirmContext(
            $firmB,
            fn () => DB::table(self::TABLE)->count(),
        );

        $this->assertGreaterThan(0, $ownVisible, 'Firm B must still see its own rows.');
    }
}
