<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Services\Pay\ProviderResourceOwnershipService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pay\Concerns\CleansUpPayAuditFixtures;
use Tests\TestCase;

/**
 * FV-A-039 — CERTIFICATION BLOCKING. Two GENUINELY concurrent attempts
 * to assign the same external provider resource to different firms.
 *
 * Required outcome (v1.4 §8):
 *   - exactly one ownership relationship succeeds
 *   - the conflicting assignment is rejected
 *   - no ambiguous ownership state
 *   - no temporary dual tenant ownership
 *
 * REAL concurrency via pcntl_fork(), following this repository's
 * established precedent in
 * tests/Feature/Security/PlatformAdminMfa/PlatformAdminRecoveryCodeRaceTest.php:
 * two separate OS processes, each with its OWN PostgreSQL connection,
 * racing the same unique index at nearly the same instant. Sequential
 * calls pretending to race would prove nothing about concurrency.
 *
 * NO RefreshDatabase, deliberately and for the same reason that test
 * documents: a forked child opens a fresh connection and can only see
 * fixture rows that are really COMMITTED. RefreshDatabase's per-test
 * transaction would make them invisible under PostgreSQL MVCC. Fixtures
 * are therefore created and explicitly cleaned up by this test itself.
 */
class ProviderResourceOwnershipRaceTest extends TestCase
{
    use CleansUpPayAuditFixtures;

    /** @var list<int> */
    private array $createdFirmIds = [];

    private ?string $racedResourceId = null;

    protected function tearDown(): void
    {
        DB::purge();

        // Purge audit fixtures BEFORE the firms below are deleted — see
        // CleansUpPayAuditFixtures for why the order cannot be reversed.
        $this->purgeAuditFixturesForFirms($this->createdFirmIds);

        if ($this->racedResourceId !== null) {
            // The model guard forbids deleting ownership rows, which is
            // correct for production. This is fixture teardown against
            // the query builder, deliberately bypassing the model.
            DB::table('integration_webhook_routing_index')
                ->where('provider_resource_id', $this->racedResourceId)
                ->delete();
        }

        if ($this->createdFirmIds !== []) {
            DB::table('firm_integrations')->whereIn('firm_id', $this->createdFirmIds)->delete();
            DB::table('firms')->whereIn('id', $this->createdFirmIds)->delete();
        }

        $this->assertNoOrphanedPayAuditRows();

        parent::tearDown();
    }

    public function test_fv_a_039_two_concurrent_ownership_assignments_leave_exactly_one_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available — cannot exercise genuine process-level concurrency.');
        }

        $provider = IntegrationProvider::query()->first()
            ?? IntegrationProvider::factory()->create();

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createdFirmIds = [(int) $firmA->id, (int) $firmB->id];

        $tenant = new TenantContextService;

        $connectionA = $tenant->runWithFirmContext($firmA, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firmA->id,
            'integration_provider_id' => $provider->id,
        ]));
        $connectionB = $tenant->runWithFirmContext($firmB, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'integration_provider_id' => $provider->id,
        ]));

        $resourceId = 'RACE-'.Str::random(12);
        $this->racedResourceId = $resourceId;

        // The fixtures must really be committed before forking, or the
        // whole test is meaningless. firm_integrations is FORCE RLS, so
        // the verification itself must run under each firm's context —
        // reading it without context correctly returns nothing.
        $this->assertNotNull(
            $tenant->runWithFirmContext($firmA, fn () => DB::table('firm_integrations')->find($connectionA->id)),
            'Firm A connection fixture must be committed and visible before forking.'
        );
        $this->assertNotNull(
            $tenant->runWithFirmContext($firmB, fn () => DB::table('firm_integrations')->find($connectionB->id)),
            'Firm B connection fixture must be committed and visible before forking.'
        );

        $childResultFile = tempnam(sys_get_temp_dir(), 'fvpay_own_child_');
        $parentResultFile = tempnam(sys_get_temp_dir(), 'fvpay_own_parent_');

        DB::disconnect();
        DB::purge();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed — cannot run this race test.');
        }

        $attempt = function (int $firmId, int $connectionId) use ($provider, $resourceId): string {
            try {
                DB::purge();
                app(ProviderResourceOwnershipService::class)->establishOwnership(
                    $firmId,
                    $connectionId,
                    (int) $provider->id,
                    'payment',
                    $resourceId,
                );

                return '1';
            } catch (\Throwable) {
                // Either the unique index rejected the insert, or the
                // service arbitrated it into an explicit ownership
                // conflict. Both are "this attempt did not win".
                return '0';
            }
        };

        if ($pid === 0) {
            try {
                file_put_contents($childResultFile, $attempt((int) $firmB->id, (int) $connectionB->id));
            } catch (\Throwable) {
                file_put_contents($childResultFile, '0');
            }

            exit(0);
        }

        try {
            file_put_contents($parentResultFile, $attempt((int) $firmA->id, (int) $connectionA->id));
        } catch (\Throwable) {
            file_put_contents($parentResultFile, '0');
        }

        pcntl_waitpid($pid, $status);

        $childWon = (int) trim((string) file_get_contents($childResultFile));
        $parentWon = (int) trim((string) file_get_contents($parentResultFile));

        @unlink($childResultFile);
        @unlink($parentResultFile);

        DB::purge();

        $this->assertSame(
            1,
            $childWon + $parentWon,
            'Exactly one concurrent ownership assignment must succeed; got parent='.$parentWon.' child='.$childWon.'.'
        );

        $rows = DB::table('integration_webhook_routing_index')
            ->where('provider_resource_type', 'payment')
            ->where('provider_resource_id', $resourceId)
            ->get();

        $this->assertCount(
            1,
            $rows,
            'No ambiguous or dual tenant ownership state may exist for one provider resource.'
        );

        $ownerFirmId = (int) $rows->first()->firm_id;

        $this->assertContains($ownerFirmId, [(int) $firmA->id, (int) $firmB->id]);
        $this->assertSame(
            'active',
            $rows->first()->ownership_status,
            'The surviving ownership row must be a real, active assignment.'
        );
    }
}
