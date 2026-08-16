<?php

declare(strict_types=1);

namespace Tests\Feature\Pay\Concerns;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentDestinationClass;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
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
}
