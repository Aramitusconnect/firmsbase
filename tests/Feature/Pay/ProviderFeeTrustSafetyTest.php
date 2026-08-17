<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\PaymentDestinationClass;
use App\Enums\ProviderFeeDirection;
use App\Exceptions\Pay\TrustExecutionDisabledException;
use App\Models\PaymentAttempt;
use App\Services\Pay\Data\ProviderFeeEvidence;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use App\Services\Pay\ProviderCommandExecutorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\BuildsPayFixtures;
use Tests\TestCase;

/**
 * FV-A3-050 … FV-A3-054 + the §46 provider-neutrality proof.
 * CERTIFICATION BLOCKING throughout (v1.4 §36/§37/§46).
 */
class ProviderFeeTrustSafetyTest extends TestCase
{
    use BuildsPayFixtures, RefreshDatabase;

    /** FV-A3-050 — a fee DEBIT is representable provider-neutrally. */
    public function test_fv_a3_050_fee_debit_represented_provider_neutrally(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        app(ProviderCommandExecutorService::class)->execute($this->payCommandOf($attempt));

        $reference = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id)->provider_reference);

        $fees = $this->payFake()->getFeeEvidence((string) $reference);
        $debits = array_values(array_filter($fees, fn (ProviderFeeEvidence $f) => $f->direction === ProviderFeeDirection::Debit));

        $this->assertNotEmpty($debits);
        $this->assertGreaterThanOrEqual(0, $debits[0]->amountCents);
        $this->assertSame('processing', $debits[0]->categoryOrUnknown());
        // provider_metadata is opaque — core reads canonical fields only.
        $this->assertIsArray($debits[0]->providerMetadata);
    }

    /** FV-A3-051 — a fee CREDIT is representable provider-neutrally. */
    public function test_fv_a3_051_fee_credit_represented_provider_neutrally(): void
    {
        $fees = $this->payFake()->getFeeEvidence('fpr_any');
        $credits = array_values(array_filter($fees, fn (ProviderFeeEvidence $f) => $f->direction === ProviderFeeDirection::Credit));

        $this->assertNotEmpty($credits);
        $this->assertGreaterThanOrEqual(0, $credits[0]->amountCents);
    }

    /** FV-A3-052 — an UNKNOWN fee category is retained safely, never invented. */
    public function test_fv_a3_052_unknown_fee_category_is_retained_safely(): void
    {
        $fees = $this->payFake()->getFeeEvidence('fpr_any');
        $uncategorized = array_values(array_filter($fees, fn (ProviderFeeEvidence $f) => $f->category === null));

        $this->assertNotEmpty($uncategorized, 'A fee line without a category must be representable.');
        $this->assertSame('unknown', $uncategorized[0]->categoryOrUnknown());

        // Magnitudes are non-negative by construction.
        $this->expectException(\InvalidArgumentException::class);
        new ProviderFeeEvidence(-1, ProviderFeeDirection::Debit, null);
    }

    /**
     * FV-A3-053 — trust_execution_mode = DISABLED end to end: a trust
     * allocation cannot create an executable command, and the fake
     * provider adapter is NEVER called (v1.4 §37).
     */
    public function test_fv_a3_053_trust_allocation_never_reaches_the_fake_provider(): void
    {
        $firm = $this->payFirmWithAccounting();
        $fake = $this->payFake();
        $callsBefore = $fake->paymentCalls + $fake->refundCalls + $fake->lookupCalls;

        $intents = app(PaymentIntentService::class);
        $intent = $intents->createDraft($firm, 700_000, 'invoice_payment');
        $intents->addAllocation($intent, PaymentDestinationClass::Trust, 700_000);
        $frozen = $intents->freeze($intent);

        try {
            app(PaymentAttemptService::class)->open($frozen, null, null, 'fake:success');
            $this->fail('A trust-destined intent must never open an attempt.');
        } catch (TrustExecutionDisabledException) {
            // expected
        }

        $this->assertSame(
            $callsBefore,
            $fake->paymentCalls + $fake->refundCalls + $fake->lookupCalls,
            'FakePaymentProviderAdapter must NEVER be called for trust-classified value.'
        );

        $this->runWithFirmContext($firm, function () {
            $this->assertSame(0, DB::table('provider_commands')->count());
            $this->assertSame(0, DB::table('integration_outbox_events')->count());
        });
    }

    /**
     * FV-A3-054 — the processor-fee path cannot debit trust principal:
     * a full provider capture cycle touches no trust table and posts
     * only operating-side accounts.
     */
    public function test_fv_a3_054_processor_fee_cannot_debit_trust_principal(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        app(ProviderCommandExecutorService::class)->execute($this->payCommandOf($attempt));

        $this->runWithFirmContext($firm, function () use ($attempt) {
            foreach (['trust_ledgers', 'trust_ledger_entries', 'trust_balances', 'matter_trust_balances', 'trust_accounts'] as $table) {
                $this->assertSame(0, DB::table($table)->count(),
                    "The provider execution path must never write [{$table}].");
            }

            // The capture debits PROCESSOR_CLEARING_OPERATING, not cash
            // and not any trust-side account (v1.4 §38).
            $entry = DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->first();
            $this->assertNotNull($entry);

            $debited = DB::table('accounting_postings')
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'accounting_postings.chart_of_account_id')
                ->where('accounting_postings.accounting_journal_entry_id', $entry->id)
                ->where('accounting_postings.debit_cents', '>', 0)
                ->pluck('chart_of_accounts.purpose')
                ->all();

            $this->assertSame([ChartOfAccountPurpose::ProcessorClearingOperating->value], $debited);
            $this->assertNotContains(ChartOfAccountPurpose::OperatingCash->value, $debited,
                'Settlement must never silently become Operating Cash (v1.4 §38).');
        });
    }

    /**
     * §46 — structural provider-neutrality: the new Pay execution flow
     * depends on no Finix/Stripe/LawPay class. Opaque provider
     * references are permitted; provider-native business logic is not.
     */
    public function test_payment_core_has_no_provider_specific_dependency(): void
    {
        $scanRoots = [
            app_path('Services/Pay'),
            app_path('Models/PaymentIntent.php'),
            app_path('Models/PaymentIntentAllocation.php'),
            app_path('Models/PaymentAttempt.php'),
            app_path('Models/PaymentRefund.php'),
            app_path('Models/ProviderCommand.php'),
            app_path('Enums/ProviderOutcome.php'),
            app_path('Enums/ProviderCommandType.php'),
            app_path('Enums/PaymentAttemptState.php'),
            app_path('Enums/PaymentRefundState.php'),
        ];

        $files = [];
        foreach ($scanRoots as $root) {
            if (is_dir($root)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->getExtension() === 'php') {
                        $files[] = $f->getPathname();
                    }
                }
            } else {
                $files[] = $root;
            }
        }

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+.*(Finix|LawPay)/im',
                $source,
                basename($file).' must not import a provider-specific class.'
            );
            // Stripe: the ONLY permitted reference in the Pay namespace is
            // the reused simulation-policy service, which is provider-
            // agnostic despite its namespace of historical origin.
            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+.*Stripe\\\\(?!PaymentGatewaySimulationPolicyService)/im',
                $source,
                basename($file).' must not import a Stripe class.'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\\bfinix\\b/i',
                preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $source) ?? '',
                basename($file).' must not reference Finix outside comments.'
            );
        }
    }
}
