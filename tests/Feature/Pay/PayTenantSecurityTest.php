<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\PaymentDestinationClass;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\AccountingJournalPostingService;
use App\Services\Pay\PaymentIntentService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pay\Concerns\CleansUpDurablePayAudit;
use Tests\TestCase;

/**
 * FV-A2-060 … FV-A2-066 — tenant security for the new FirmsVault Pay
 * path. ALL CERTIFICATION BLOCKING.
 *
 * Every new tenant-owned financial table must be RLS + FORCE RLS
 * protected, registered in the coverage registry, and structurally
 * incapable of holding a cross-firm reference.
 */
class PayTenantSecurityTest extends TestCase
{
    use CleansUpDurablePayAudit;
    use RefreshDatabase;

    /** Every new tenant-owned Pay table. */
    private const PAY_TENANT_TABLES = [
        'payment_intents',
        'payment_intent_allocations',
        'provider_commands',
        'payment_attempts',
        'payment_refunds',
        'provider_evidence_artifacts',
    ];

    /** FV-A2-060 / FV-A2-061 — RLS and FORCE RLS on every new table. */
    public function test_fv_a2_060_and_061_all_new_pay_tables_have_rls_and_force_rls(): void
    {
        foreach (self::PAY_TENANT_TABLES as $table) {
            $row = DB::selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
                [$table]
            );

            $this->assertNotNull($row, "Table [{$table}] must exist.");
            $this->assertTrue((bool) $row->relrowsecurity, "Table [{$table}] must have ROW LEVEL SECURITY enabled.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "Table [{$table}] must have FORCE ROW LEVEL SECURITY.");

            $policies = DB::select('select policyname from pg_policies where tablename = ?', [$table]);
            $this->assertNotEmpty($policies, "Table [{$table}] must carry a tenant isolation policy.");
        }
    }

    /** Every new table is registered in the governed coverage registry. */
    public function test_all_new_pay_tables_are_registered_in_the_rls_coverage_registry(): void
    {
        $registry = app(RowLevelSecurityCoverageMappingService::class);

        foreach (self::PAY_TENANT_TABLES as $table) {
            $this->assertTrue(
                $registry->isPrepared($table),
                "Table [{$table}] must be registered as prepared in RowLevelSecurityCoverageMappingService — "
                .'adding a tenant table without registering it is a governed violation.'
            );
            $this->assertFalse($registry->isMissing($table));
        }
    }

    /** FV-A2-060 — with no tenant context, nothing is readable or writable. */
    public function test_fv_a2_060_missing_tenant_context_can_neither_read_nor_write_pay_tables(): void
    {
        $firm = Firm::factory()->create();
        $intent = $this->executableIntent($firm, 10_000);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PaymentIntent::query()->count(), 'A contextless read must return nothing.');

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_intents')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'amount_cents' => 500,
            'currency' => 'USD',
            'purpose' => 'invoice_payment',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** FV-A2-062 — Firm A cannot see or reference Firm B's PaymentIntent. */
    public function test_fv_a2_062_firm_a_cannot_read_firm_b_payment_intents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $intentA = $this->executableIntent($firmA, 10_000);
        $intentB = $this->executableIntent($firmB, 20_000);

        $visible = $this->runWithFirmContext($firmA, fn () => PaymentIntent::query()->pluck('id')->all());

        $this->assertContains($intentA->id, $visible);
        $this->assertNotContains($intentB->id, $visible, 'Firm A must never see Firm B payment intents.');
    }

    /**
     * FV-A2-062 — and the DATABASE refuses a cross-firm reference, not
     * just RLS hiding it. The composite FK is the mechanism.
     */
    public function test_fv_a2_062_database_refuses_a_cross_firm_payment_intent_reference(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $intentB = $this->executableIntent($firmB, 20_000);

        $this->expectException(QueryException::class);

        // Firm A attempts an allocation against Firm B's intent.
        $this->runWithFirmContext($firmA, fn () => DB::table('payment_intent_allocations')->insert([
            'firm_id' => $firmA->id,
            'payment_intent_id' => $intentB->id,
            'destination_class' => 'operating',
            'amount_cents' => 1_000,
            'created_at' => now(),
        ]));
    }

    /** FV-A2-062 — the same guarantee for attempts against a foreign intent. */
    public function test_fv_a2_062_database_refuses_a_cross_firm_payment_attempt(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $intentB = $this->executableIntent($firmB, 20_000);

        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firmA, fn () => DB::table('payment_attempts')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firmA->id,
            'payment_intent_id' => $intentB->id,
            'state' => 'created',
            'amount_cents' => 1_000,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * FV-A2-064 — Firm A cannot post a new payment journal entry
     * against Firm B's attempt. The composite FK added by Gate A2 makes
     * this structurally impossible.
     */
    public function test_fv_a2_064_firm_a_cannot_post_a_journal_entry_against_firm_b_payment_attempt(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $intentB = $this->executableIntent($firmB, 20_000);

        $attemptB = $this->runWithFirmContext($firmB, fn () => PaymentAttempt::factory()
            ->forFirm($firmB)
            ->create(['payment_intent_id' => $intentB->id]));

        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firmA, fn () => DB::table('accounting_journal_entries')->insert([
            'firm_id' => $firmA->id,
            'entry_date' => now()->toDateString(),
            'description' => 'cross-firm attempt',
            'source_type' => 'provider_payment_captured',
            'payment_attempt_id' => $attemptB->id,
            'created_at' => now(),
        ]));
    }

    /**
     * FV-A2-064 — and the existing ledger service still refuses a
     * posting against another firm's chart-of-accounts row.
     */
    public function test_fv_a2_064_posting_against_another_firms_account_is_refused(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->forFirm($firmB)->create([
            'purpose' => ChartOfAccountPurpose::OperatingCash,
            'account_type' => ChartOfAccountType::Asset,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not belong to this firm|not active/');

        app(AccountingJournalPostingService::class)->post(
            $firmA,
            AccountingJournalSourceType::ProviderPaymentCaptured,
            'cross-firm posting attempt',
            now(),
            [
                ['chart_of_account_id' => $accountB->id, 'debit_cents' => 100, 'credit_cents' => 0],
                ['chart_of_account_id' => $accountB->id, 'debit_cents' => 0, 'credit_cents' => 100],
            ],
        );
    }

    /**
     * FV-A2-065 — a worker-style mutation without validated tenant
     * context is refused by the database, not merely by convention.
     */
    public function test_fv_a2_065_worker_mutation_requires_validated_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $intent = $this->executableIntent($firm, 10_000);

        // Simulate a background worker that forgot to establish context.
        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('payment_intents')
            ->where('id', $intent->id)
            ->update(['purpose' => 'tampered']);

        $this->assertSame(
            0,
            $affected,
            'A contextless worker must not be able to mutate tenant financial rows.'
        );

        // And the row is genuinely untouched.
        $reread = $this->runWithFirmContext($firm, fn () => PaymentIntent::query()->findOrFail($intent->id));
        $this->assertSame('invoice_payment', $reread->purpose);
    }

    /**
     * v1.4 §41 — unresolved provider payloads are not stored in
     * tenant-visible tables. Gate A2 satisfies this by CONSTRUCTION:
     * provider_evidence_artifacts requires a firm, so an unattributed
     * artifact cannot exist here at all. Unresolved ingress stays in
     * the Global/EXEMPT integration_webhook_receipts.
     */
    public function test_provider_evidence_cannot_hold_an_unattributed_artifact(): void
    {
        $firm = Firm::factory()->create();

        // firm_id is NOT NULL — an unattributed artifact is rejected by
        // the database, not merely hidden by a policy.
        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firm, fn () => DB::table('provider_evidence_artifacts')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => null,
            'evidence_type' => 'inbound_event',
            'content_sha256' => hash('sha256', 'unresolved'),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** A firm-attributed artifact is readable only by its own firm. */
    public function test_provider_evidence_is_isolated_per_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => DB::table('provider_evidence_artifacts')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firmA->id,
            'evidence_type' => 'provider_response',
            'content_sha256' => hash('sha256', 'firm-a-evidence'),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame(
            1,
            $this->runWithFirmContext($firmA, fn () => DB::table('provider_evidence_artifacts')->count())
        );
        $this->assertSame(
            0,
            $this->runWithFirmContext($firmB, fn () => DB::table('provider_evidence_artifacts')->count()),
            'Firm B must never see Firm A provider evidence.'
        );
    }

    private function executableIntent(Firm $firm, int $amountCents): PaymentIntent
    {
        $intents = app(PaymentIntentService::class);
        $intent = $intents->createDraft($firm, $amountCents, 'invoice_payment');
        $intents->addAllocation($intent, PaymentDestinationClass::Operating, $amountCents);

        return $intents->freeze($intent);
    }
}
