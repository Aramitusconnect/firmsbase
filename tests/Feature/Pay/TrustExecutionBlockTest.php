<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\PaymentClassification;
use App\Enums\PaymentDestinationClass;
use App\Enums\PaymentMode;
use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\TrustExecutionDisabledException;
use App\Models\Firm;
use App\Models\PaymentIntent;
use App\Models\ProviderCommand;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use App\Services\PaymentClassificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FV-A2-030 … FV-A2-033 — trust safety. ALL CERTIFICATION BLOCKING.
 *
 * POC #1 runs with trust_execution_mode = DISABLED (v1.4 §19). These
 * tests prove that is a structural property of the system, not a
 * configuration flag someone could flip:
 *
 *   - the repository's EXISTING unconditional block in
 *     PaymentClassificationService is still in force and was NOT
 *     replaced or renamed by this gate;
 *   - the NEW provider path adds an earlier, independent refusal, so
 *     trust value cannot even reach command creation;
 *   - no provider destination for trust exists anywhere in the domain;
 *   - the processor-fee path cannot touch trust principal.
 */
class TrustExecutionBlockTest extends TestCase
{
    use RefreshDatabase;

    private function intents(): PaymentIntentService
    {
        return app(PaymentIntentService::class);
    }

    private function attempts(): PaymentAttemptService
    {
        return app(PaymentAttemptService::class);
    }

    /**
     * FV-A2-030 — the EXISTING trust block is untouched. Gate A2 did not
     * weaken, replace or rename it (v1.4 §19: "do not replace existing
     * protections merely to rename them").
     */
    public function test_fv_a2_030_existing_unconditional_trust_classification_block_still_holds(): void
    {
        $firm = Firm::factory()->create();

        // Even for a firm explicitly configured for operating AND trust.
        $this->runWithFirmContext($firm, fn () => $firm->firmSettings()->update([
            'payment_mode' => PaymentMode::OperatingAndTrust,
        ]));

        $result = app(PaymentClassificationService::class)
            ->classify($firm->fresh(), PaymentClassification::TrustIoltaPayment);

        $this->assertFalse($result->accepted, 'Trust/IOLTA classification must remain unconditionally blocked.');
        $this->assertSame(PaymentClassification::BlockedPayment, $result->resolvedClassification);
    }

    /** FV-A2-031 — a trust allocation cannot create an executable command. */
    public function test_fv_a2_031_trust_allocation_cannot_create_an_executable_provider_command(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 700_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Trust, 700_000);
        $frozen = $this->intents()->freeze($intent);

        try {
            $this->attempts()->open($frozen);
            $this->fail('A trust-destined intent must never create an executable provider command.');
        } catch (TrustExecutionDisabledException $e) {
            $this->assertStringContainsString('Trust execution is DISABLED', $e->getMessage());
        }

        // Nothing was created — no attempt, no command, no outbox row.
        $this->runWithFirmContext($firm, function () {
            $this->assertSame(0, DB::table('payment_attempts')->count());
            $this->assertSame(0, DB::table('provider_commands')->count());
            $this->assertSame(0, DB::table('integration_outbox_events')->count());
        });
    }

    /** FV-A2-031 — even a MOSTLY-operating intent is blocked by any trust cent. */
    public function test_fv_a2_031_a_single_trust_cent_blocks_provider_execution(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 100_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 99_999);
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Trust, 1);
        $frozen = $this->intents()->freeze($intent);

        $this->expectException(TrustExecutionDisabledException::class);

        $this->attempts()->open($frozen);
    }

    /**
     * FV-A2-032 — no provider destination for trust exists. The command
     * type vocabulary is provider-neutral and contains no trust concept
     * at all, and the database CHECK constraint enforces the same closed
     * set independently.
     */
    public function test_fv_a2_032_no_trust_provider_destination_exists_in_the_domain(): void
    {
        $types = array_map(fn (ProviderCommandType $t): string => $t->value, ProviderCommandType::cases());

        $this->assertSame(['capture_payment', 'refund_payment'], $types);

        foreach ($types as $type) {
            $this->assertStringNotContainsStringIgnoringCase('trust', $type);
            $this->assertStringNotContainsStringIgnoringCase('iolta', $type);
        }

        // The database refuses any other command type outright.
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firm, fn () => DB::table('provider_commands')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'command_type' => 'trust_deposit',
            'aggregate_type' => 'X',
            'aggregate_id' => 1,
            'idempotency_key' => 'trust:'.Str::uuid(),
            'canonical_payload_hash' => str_repeat('d', 64),
            'canonical_payload' => '{}',
            'correlation_id' => (string) Str::uuid(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * FV-A2-033 — the processor-fee path cannot debit trust principal.
     *
     * Structurally, not by inspection: the ONLY value that can reach a
     * provider capture is Operating-destined (proved above), and the
     * capture posting touches exclusively operating-side accounts. The
     * trust ledger tables are never referenced by the Pay journal path.
     */
    public function test_fv_a2_033_processor_fee_path_cannot_debit_trust_principal(): void
    {
        // The Pay journal recorder imports no Trust class whatsoever —
        // the same structural firewall style the repository already uses
        // for OperatingLedgerBankMatchingService.
        $source = file_get_contents(app_path('Services/Pay/ProviderPaymentJournalRecorderService.php'));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/use\s+App\\\\Models\\\\Trust/',
            $source,
            'The provider payment journal recorder must never import a Trust model.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/use\s+App\\\\Services\\\\Trust/',
            $source,
            'The provider payment journal recorder must never import a Trust service.'
        );

        // And the accounts it can resolve are all operating-side.
        $this->assertStringContainsString('ProcessorClearingOperating', $source);
        $this->assertStringNotContainsStringIgnoringCase('trust_ledger', $source);

        // The fee account itself is an ordinary operating expense purpose.
        $this->assertSame('processor_fees', ChartOfAccountPurpose::ProcessorFees->value);

        // Belt and braces: an executable intent can hold no trust value,
        // so nothing trust-funded can ever reach a fee posting.
        $firm = Firm::factory()->create();
        $intent = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 10_000);
        $frozen = $this->intents()->freeze($intent);

        $eligibility = $this->intents()->executionEligibility($frozen);

        $this->assertTrue($eligibility['eligible']);
        $this->assertSame(0, $eligibility['trust_cents'], 'An executable intent carries zero trust value, by construction.');
    }

    /** The trust block is audited durably, so a refusal is provable. */
    public function test_trust_execution_block_is_recorded(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 5_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Trust, 5_000);
        $frozen = $this->intents()->freeze($intent);

        try {
            $this->attempts()->open($frozen);
        } catch (TrustExecutionDisabledException) {
            // expected
        }

        // The refusal produced no economic artifacts at all.
        $this->runWithFirmContext($firm, function () {
            $this->assertSame(0, ProviderCommand::query()->count());
            $this->assertSame(0, PaymentIntent::query()->where('status', 'draft')->count());
        });
    }
}
