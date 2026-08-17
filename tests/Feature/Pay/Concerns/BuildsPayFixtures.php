<?php

declare(strict_types=1);

namespace Tests\Feature\Pay\Concerns;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\PaymentAttemptState;
use App\Enums\PaymentDestinationClass;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\ProviderCommand;
use App\Services\EntitlementService;
use App\Services\Pay\Contracts\PaymentProviderAdapter;
use App\Services\Pay\Fake\FakePaymentProviderAdapter;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use App\Services\Pay\RefundReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BuildsPayFixtures — shared fixture builders for the FirmsVault Pay
 * Gate A2 RLS activation tests.
 *
 * Every row is created through the REAL service path wherever one
 * exists, so an activation test cannot accidentally pass against a row
 * shape the production code could never produce.
 */
trait BuildsPayFixtures
{
    /**
     * Create exactly one valid row in $table belonging to $firm.
     */
    protected function seedPayRowFor(Firm $firm, string $table): void
    {
        match ($table) {
            'payment_intents' => $this->payDraftIntent($firm),
            'payment_intent_allocations' => $this->payAllocatedIntent($firm),
            'provider_commands', 'payment_attempts' => $this->payOpenAttempt($firm),
            'payment_refunds' => $this->payReservedRefund($firm),
            'provider_evidence_artifacts' => $this->payEvidenceArtifact($firm),
            default => throw new \InvalidArgumentException("No Pay fixture builder for table [{$table}]."),
        };
    }

    protected function payDraftIntent(Firm $firm, int $amountCents = 10_000): PaymentIntent
    {
        return app(PaymentIntentService::class)->createDraft($firm, $amountCents, 'invoice_payment');
    }

    protected function payAllocatedIntent(Firm $firm, int $amountCents = 10_000): PaymentIntent
    {
        $intents = app(PaymentIntentService::class);
        $intent = $intents->createDraft($firm, $amountCents, 'invoice_payment');
        $intents->addAllocation($intent, PaymentDestinationClass::Operating, $amountCents);

        return $intent;
    }

    protected function payFrozenIntent(Firm $firm, int $amountCents = 10_000): PaymentIntent
    {
        return app(PaymentIntentService::class)->freeze($this->payAllocatedIntent($firm, $amountCents));
    }

    /** Creates a PaymentAttempt AND its ProviderCommand, atomically. */
    protected function payOpenAttempt(Firm $firm, int $amountCents = 10_000): PaymentAttempt
    {
        return app(PaymentAttemptService::class)->open($this->payFrozenIntent($firm, $amountCents));
    }

    protected function payCapturedAttempt(Firm $firm, int $amountCents = 10_000): PaymentAttempt
    {
        $attempts = app(PaymentAttemptService::class);
        $attempt = $this->payOpenAttempt($firm, $amountCents);
        $submitted = $attempts->transition($attempt, PaymentAttemptState::Submitted);

        return $attempts->transition($submitted, PaymentAttemptState::Captured, providerReference: 'FIXTURE-CAP');
    }

    protected function payReservedRefund(Firm $firm, int $amountCents = 10_000): void
    {
        app(RefundReservationService::class)->reserve(
            $this->payCapturedAttempt($firm, $amountCents),
            (int) ($amountCents / 2),
        );
    }

    /**
     * No service writes evidence artifacts yet (Gate A3+ work), so this
     * is a direct insert of the minimal valid row, under firm context.
     */
    protected function payEvidenceArtifact(Firm $firm): void
    {
        $this->runWithFirmContext($firm, fn () => DB::table('provider_evidence_artifacts')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'evidence_type' => 'provider_response',
            'content_sha256' => hash('sha256', 'fixture-evidence-'.$firm->id),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Gate A3 — a firm with the accounting module enabled and every
     * chart-of-accounts purpose the provider capture/refund postings can
     * resolve. Mirrors ProviderPaymentAccountingTest's own builder.
     */
    protected function payFirmWithAccounting(): Firm
    {
        $firm = Firm::factory()->create();

        app(EntitlementService::class)->setForSource(
            $firm, 'expenses', EntitlementSource::AdminOverride, true,
        );

        $purposes = [
            [ChartOfAccountPurpose::OperatingCash, ChartOfAccountType::Asset],
            [ChartOfAccountPurpose::LegalFeeRevenue, ChartOfAccountType::Revenue],
            [ChartOfAccountPurpose::CostReimbursementRevenue, ChartOfAccountType::Revenue],
            [ChartOfAccountPurpose::ProcessorClearingOperating, ChartOfAccountType::Asset],
            [ChartOfAccountPurpose::ProviderSettlementReceivable, ChartOfAccountType::Asset],
            [ChartOfAccountPurpose::ProcessorFees, ChartOfAccountType::Expense],
        ];

        $this->runWithFirmContext($firm, function () use ($firm, $purposes) {
            foreach ($purposes as [$purpose, $type]) {
                ChartOfAccount::factory()->forFirm($firm)->create([
                    'purpose' => $purpose,
                    'account_type' => $type,
                    'is_active' => true,
                ]);
            }
        });

        return $firm;
    }

    /**
     * Gate A3 — a provider-platform connection pair for the fake
     * provider: the IntegrationProvider (ProviderPlatformConnection
     * role) and the firm's FirmIntegration (FirmProviderAccount role).
     *
     * @return array{0: IntegrationProvider, 1: FirmIntegration}
     */
    protected function payProviderConnection(Firm $firm): array
    {
        $provider = IntegrationProvider::query()->first()
            ?? IntegrationProvider::factory()->create();

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
        ]));

        return [$provider, $connection];
    }

    /**
     * Gate A3 — an opened attempt whose canonical payload carries the
     * fake-provider scenario token, bound to a real provider connection.
     */
    protected function payOpenAttemptWithToken(
        Firm $firm,
        IntegrationProvider $provider,
        FirmIntegration $connection,
        string $token,
        int $amountCents = 10_000,
    ): PaymentAttempt {
        return app(PaymentAttemptService::class)->open(
            $this->payFrozenIntent($firm, $amountCents),
            (int) $connection->id,
            (int) $provider->id,
            $token,
        );
    }

    protected function payFake(): FakePaymentProviderAdapter
    {
        $adapter = app(PaymentProviderAdapter::class);

        if (! $adapter instanceof FakePaymentProviderAdapter) {
            $this->fail('The fake payment provider adapter must be bound in the testing environment.');
        }

        return $adapter;
    }

    protected function payCommandOf(PaymentAttempt|PaymentRefund $aggregate): ProviderCommand
    {
        return $this->runWithFirmContext(
            (int) $aggregate->firm_id,
            fn () => ProviderCommand::query()->findOrFail($aggregate->provider_command_id),
        );
    }
}
