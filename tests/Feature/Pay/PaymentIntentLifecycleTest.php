<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentDestinationClass;
use App\Enums\PaymentIntentStatus;
use App\Exceptions\Pay\PaymentIntentNotExecutableException;
use App\Exceptions\Pay\TrustExecutionDisabledException;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\ProviderCommand;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pay\Concerns\CleansUpDurablePayAudit;
use Tests\TestCase;

/**
 * FV-A2-020 … FV-A2-028 — payment core. CERTIFICATION BLOCKING.
 *
 * The central thing these tests exist to pin down is v1.4 §18's
 * separation: ALLOCATION COMPLETENESS and EXECUTION ELIGIBILITY are two
 * different questions, and a mixed operating/trust intent answers YES to
 * the first and NO to the second at the same time.
 */
class PaymentIntentLifecycleTest extends TestCase
{
    use CleansUpDurablePayAudit;
    use RefreshDatabase;

    private function intents(): PaymentIntentService
    {
        return app(PaymentIntentService::class);
    }

    private function attempts(): PaymentAttemptService
    {
        return app(PaymentAttemptService::class);
    }

    /** FV-A2-020 — a valid USD operating intent freezes and is executable. */
    public function test_fv_a2_020_valid_usd_operating_payment_intent(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 10_000);

        $frozen = $this->intents()->freeze($intent);

        $this->assertSame(PaymentIntentStatus::Frozen, $frozen->status);
        $this->assertNotNull($frozen->frozen_at);
        $this->assertNotNull($frozen->material_fingerprint);
        $this->assertSame('USD', $frozen->currency);

        $eligibility = $this->intents()->executionEligibility($frozen);

        $this->assertTrue($eligibility['eligible']);
        $this->assertSame(10_000, $eligibility['operating_cents']);
        $this->assertSame(0, $eligibility['trust_cents']);
    }

    /** FV-A2-021 — non-USD is rejected. */
    public function test_fv_a2_021_non_usd_currency_is_rejected(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/USD only/');

        $this->intents()->createDraft($firm, 10_000, 'invoice_payment', currency: 'EUR');
    }

    /** FV-A2-021 — and the database refuses it too, independently. */
    public function test_fv_a2_021_database_enforces_usd_only(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firm, fn () => DB::table('payment_intents')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'amount_cents' => 1000,
            'currency' => 'EUR',
            'purpose' => 'invoice_payment',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** FV-A2-022 — zero and negative amounts are rejected. */
    public function test_fv_a2_022_zero_or_negative_amount_is_rejected(): void
    {
        $firm = Firm::factory()->create();

        foreach ([0, -1, -5000] as $bad) {
            try {
                $this->intents()->createDraft($firm, $bad, 'invoice_payment');
                $this->fail("An amount of {$bad} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('greater than zero', $e->getMessage());
            }
        }
    }

    /** FV-A2-022 — the database refuses a non-positive amount independently. */
    public function test_fv_a2_022_database_enforces_positive_amount(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firm, fn () => DB::table('payment_intents')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'amount_cents' => 0,
            'currency' => 'USD',
            'purpose' => 'invoice_payment',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** FV-A2-023 — allocations must total the intent amount exactly. */
    public function test_fv_a2_023_allocation_total_must_equal_intent_amount(): void
    {
        $firm = Firm::factory()->create();

        // Under-allocated.
        $under = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($under, PaymentDestinationClass::Operating, 6_000);

        $this->assertFalse($this->intents()->allocationsAreComplete($under));

        try {
            $this->intents()->freeze($under);
            $this->fail('An under-allocated intent must not freeze.');
        } catch (PaymentIntentNotExecutableException $e) {
            $this->assertStringContainsString('Allocation completeness failed', $e->getMessage());
        }

        // Over-allocated.
        $over = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($over, PaymentDestinationClass::Operating, 12_000);

        $this->expectException(PaymentIntentNotExecutableException::class);
        $this->intents()->freeze($over);
    }

    /**
     * FV-A2-024 — THE §18 EXAMPLE, verbatim.
     *
     *   Intent   = $10,000
     *   Operating = $3,000
     *   Trust     = $7,000
     *
     *   Allocation completeness: PASS
     *   Execution eligibility:   BLOCKED (trust execution disabled)
     */
    public function test_fv_a2_024_mixed_operating_and_trust_is_complete_but_not_executable(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 1_000_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 300_000);
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Trust, 700_000);

        // Completeness: PASS. The instruction is well-formed.
        $this->assertTrue(
            $this->intents()->allocationsAreComplete($intent),
            'A mixed operating/trust intent whose allocations total the amount IS complete.'
        );

        $frozen = $this->intents()->freeze($intent);
        $this->assertSame(PaymentIntentStatus::Frozen, $frozen->status);

        // Eligibility: BLOCKED. Two different questions.
        $eligibility = $this->intents()->executionEligibility($frozen);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('trust_execution_disabled', $eligibility['reason']);
        $this->assertSame(300_000, $eligibility['operating_cents']);
        $this->assertSame(700_000, $eligibility['trust_cents']);

        // And it genuinely cannot execute — not even for the operating part.
        $this->expectException(TrustExecutionDisabledException::class);
        $this->attempts()->open($frozen);
    }

    /** FV-A2-025 — a frozen intent's material fields are immutable. */
    public function test_fv_a2_025_frozen_material_mutation_is_rejected(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 10_000);
        $frozen = $this->intents()->freeze($intent);

        foreach (['amount_cents' => 99_999, 'purpose' => 'something_else', 'currency' => 'USD'] as $field => $value) {
            if ($field === 'currency') {
                continue; // unchanged value would not be dirty
            }

            try {
                $this->runWithFirmContext($firm, fn () => $frozen->update([$field => $value]));
                $this->fail("Mutating the frozen material field [{$field}] must be refused.");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('frozen intent is immutable', $e->getMessage());
            }
        }

        // The fingerprint still matches the original material values.
        $reread = $this->runWithFirmContext($firm, fn () => PaymentIntent::query()->findOrFail($frozen->id));
        $this->assertSame($reread->material_fingerprint, $reread->computeMaterialFingerprint());
    }

    /** FV-A2-025 — allocations are append-only, so a frozen intent's split cannot shift. */
    public function test_fv_a2_025_allocations_cannot_be_added_to_a_frozen_intent(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 10_000);
        $frozen = $this->intents()->freeze($intent);

        $this->expectException(PaymentIntentNotExecutableException::class);

        $this->intents()->addAllocation($frozen, PaymentDestinationClass::Operating, 1);
    }

    /** FV-A2-026 — supersede creates a NEW intent and preserves history. */
    public function test_fv_a2_026_supersede_creates_a_new_intent_and_preserves_history(): void
    {
        $firm = Firm::factory()->create();

        $intent = $this->intents()->createDraft($firm, 10_000, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, 10_000);
        $frozen = $this->intents()->freeze($intent);
        $originalFingerprint = $frozen->material_fingerprint;

        $replacement = $this->intents()->supersede($frozen, 15_000);

        $original = $this->runWithFirmContext($firm, fn () => PaymentIntent::query()->findOrFail($frozen->id));

        // History is preserved, not rewritten.
        $this->assertSame(PaymentIntentStatus::Superseded, $original->status);
        $this->assertSame(10_000, (int) $original->amount_cents, 'The superseded intent keeps its original amount forever.');
        $this->assertSame($originalFingerprint, $original->material_fingerprint);
        $this->assertSame($replacement->id, (int) $original->superseded_by_payment_intent_id);
        $this->assertNotNull($original->superseded_at);

        // The replacement is a new, independent DRAFT instruction.
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(PaymentIntentStatus::Draft, $replacement->status);
        $this->assertSame(15_000, (int) $replacement->amount_cents);
        $this->assertSame($original->id, (int) $replacement->supersedes_payment_intent_id);
    }

    /** FV-A2-027 — the attempt transition matrix is enforced. */
    public function test_fv_a2_027_payment_attempt_transition_matrix_is_enforced(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->createWithFirmContext($firm, fn () => PaymentAttempt::factory()->forFirm($firm)->create([
            'payment_intent_id' => $this->executableIntent($firm)->id,
        ]));

        // Legal: created -> submitted -> captured
        $submitted = $this->attempts()->transition($attempt, PaymentAttemptState::Submitted);
        $this->assertSame(PaymentAttemptState::Submitted, $submitted->state);

        $captured = $this->attempts()->transition($submitted, PaymentAttemptState::Captured);
        $this->assertSame(PaymentAttemptState::Captured, $captured->state);
        $this->assertTrue($captured->state->isTerminal());

        // Illegal: captured -> anything
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Illegal payment attempt transition/');
        $this->attempts()->transition($captured, PaymentAttemptState::Declined);
    }

    /** FV-A2-027 — created cannot jump straight to captured. */
    public function test_fv_a2_027_attempt_cannot_skip_submission(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->createWithFirmContext($firm, fn () => PaymentAttempt::factory()->forFirm($firm)->create([
            'payment_intent_id' => $this->executableIntent($firm)->id,
        ]));

        $this->expectException(\LogicException::class);

        $this->attempts()->transition($attempt, PaymentAttemptState::Captured);
    }

    /**
     * FV-A2-028 — OUTCOME_UNKNOWN can never automatically generate
     * another charge. The original attempt, its command and its
     * idempotency identity are all retained.
     */
    public function test_fv_a2_028_outcome_unknown_cannot_generate_a_new_charge_command(): void
    {
        $firm = Firm::factory()->create();
        $intent = $this->executableIntent($firm);

        $attempt = $this->attempts()->open($intent);
        $originalCommandId = (int) $attempt->provider_command_id;
        $originalCommandUuid = $this->runWithFirmContext(
            $firm,
            fn () => ProviderCommand::query()->findOrFail($originalCommandId)->uuid
        );

        $submitted = $this->attempts()->transition($attempt, PaymentAttemptState::Submitted);
        $unknown = $this->attempts()->transition($submitted, PaymentAttemptState::OutcomeUnknown);

        $this->assertSame(PaymentAttemptState::OutcomeUnknown, $unknown->state);
        $this->assertTrue($unknown->state->isTerminal(), 'OUTCOME_UNKNOWN must have no automated way out.');
        $this->assertFalse($unknown->state->provesNoMoneyMoved());

        // Opening another attempt for the same intent is refused.
        try {
            $this->attempts()->open($intent);
            $this->fail('An intent with an undetermined attempt must never acquire a second charge.');
        } catch (PaymentIntentNotExecutableException $e) {
            $this->assertStringContainsString('outcome is undetermined', $e->getMessage());
        }

        // Exactly one attempt and one command still exist, unchanged.
        $this->runWithFirmContext($firm, function () use ($intent, $originalCommandId, $originalCommandUuid) {
            $this->assertSame(1, PaymentAttempt::query()->where('payment_intent_id', $intent->id)->count());
            $this->assertSame(1, ProviderCommand::query()->count());

            $command = ProviderCommand::query()->findOrFail($originalCommandId);
            $this->assertSame($originalCommandUuid, $command->uuid, 'The original idempotency identity is retained.');
            $this->assertSame('fvpay:'.$originalCommandUuid, $command->logicalOperationKey());
        });
    }

    /**
     * FV-A2-006 — command and outbox creation are atomic with the
     * domain mutation, and FV-A2-007 — the existing outbox's unique
     * constraint makes duplicate dispatch impossible.
     */
    public function test_fv_a2_006_and_007_attempt_command_and_outbox_are_atomic_and_deduplicated(): void
    {
        $firm = Firm::factory()->create();
        $intent = $this->executableIntent($firm);

        $attempt = $this->attempts()->open($intent);

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $command = ProviderCommand::query()->findOrFail($attempt->provider_command_id);

            // All three landed together.
            $this->assertNotNull($attempt->id);
            $this->assertNotNull($command->id);

            $outbox = DB::table('integration_outbox_events')
                ->where('domain_event_id', $command->uuid)
                ->get();

            $this->assertCount(1, $outbox, 'Exactly one outbox row per economic instruction.');

            // FV-A2-007 — the DATABASE refuses a duplicate dispatch row.
            $this->expectException(UniqueConstraintViolationException::class);

            DB::table('integration_outbox_events')->insert([
                'firm_id' => $attempt->firm_id,
                'domain_event_id' => $command->uuid,
                'event_type' => 'firmsvault_pay.provider_command.dispatch',
                'payload_json' => '{}',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 5,
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function executableIntent(Firm $firm, int $amountCents = 10_000): PaymentIntent
    {
        $intent = $this->intents()->createDraft($firm, $amountCents, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, $amountCents);

        return $this->intents()->freeze($intent);
    }
}
