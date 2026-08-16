<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentDestinationClass;
use App\Enums\PaymentRefundState;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use App\Services\Pay\RefundReservationService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\PreservesPayAuditAttribution;
use Tests\TestCase;

/**
 * FV-A2-051 / FV-A2-052 — refund reservation concurrency.
 * CERTIFICATION BLOCKING (v1.4 §25/§26).
 *
 * Proves the invariant
 *
 *     successful refunds + active reservations <= captured amount
 *
 * holds under GENUINE concurrency, and identifies the exact mechanism
 * that makes it hold: the `SELECT ... FOR UPDATE` on the parent
 * payment_attempts row inside RefundReservationService::reserve(), which
 * serializes every reserver for that attempt before any of them may read
 * the held-capacity sum.
 *
 * Two real OS processes (pcntl_fork), each with its OWN PostgreSQL
 * connection, both try to reserve the FULL captured amount at the same
 * instant. Without the row lock both would read "0 held" and both would
 * succeed, over-reserving by 100%.
 *
 * NO RefreshDatabase — a forked child needs committed fixtures it can
 * actually see. Follows the established precedent in
 * tests/Feature/Security/PlatformAdminMfa/PlatformAdminRecoveryCodeRaceTest.php.
 */
class RefundReservationRaceTest extends TestCase
{
    use PreservesPayAuditAttribution;

    private ?int $firmId = null;

    protected function tearDown(): void
    {
        DB::purge();

        if ($this->firmId !== null) {
            DB::table('payment_refunds')->where('firm_id', $this->firmId)->delete();
            DB::table('payment_attempts')->where('firm_id', $this->firmId)->delete();
            DB::table('provider_commands')->where('firm_id', $this->firmId)->delete();
            DB::table('integration_outbox_events')->where('firm_id', $this->firmId)->delete();
            DB::table('payment_intent_allocations')->where('firm_id', $this->firmId)->delete();
            DB::table('payment_intents')->where('firm_id', $this->firmId)->delete();
        }

        // DELIBERATELY DOES NOT DELETE THE FIRM ROW.
        // security_events.firm_id is ON DELETE SET NULL, and this test
        // writes durable Pay audit rows. Deleting the firm would orphan
        // them to firm_id = NULL, which makes them visible to every
        // CONTEXTLESS reader — and they can never be deleted afterwards,
        // because security_events has no DELETE policy under FORCE RLS
        // (it is an append-only audit log by design). Keeping the firm
        // keeps the audit trail attributed and invisible to contextless
        // readers. See Tests\Feature\Pay\Concerns\PreservesPayAuditAttribution.

        $this->assertNoOrphanedPayAuditRows();

        parent::tearDown();
    }

    public function test_fv_a2_051_two_concurrent_reservations_cannot_over_reserve(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available — cannot exercise genuine process-level concurrency.');
        }

        $capturedCents = 10_000;

        $firm = Firm::factory()->create();
        $this->firmId = (int) $firm->id;

        $tenant = new TenantContextService;
        $intents = app(PaymentIntentService::class);
        $attempts = app(PaymentAttemptService::class);

        $intent = $intents->createDraft($firm, $capturedCents, 'invoice_payment');
        $intents->addAllocation($intent, PaymentDestinationClass::Operating, $capturedCents);
        $frozen = $intents->freeze($intent);

        $attempt = $attempts->open($frozen);
        $submitted = $attempts->transition($attempt, PaymentAttemptState::Submitted);
        $captured = $attempts->transition($submitted, PaymentAttemptState::Captured, providerReference: 'CAP-RACE');

        $attemptId = (int) $captured->id;

        // The fixture must really be committed before forking.
        $this->assertNotNull(
            $tenant->runWithFirmContext($firm, fn () => DB::table('payment_attempts')->find($attemptId)),
            'The captured attempt fixture must be committed and visible before forking.'
        );

        $childResultFile = tempnam(sys_get_temp_dir(), 'fvpay_refund_child_');
        $parentResultFile = tempnam(sys_get_temp_dir(), 'fvpay_refund_parent_');

        DB::disconnect();
        DB::purge();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed — cannot run this race test.');
        }

        // Both sides try to reserve the ENTIRE captured amount.
        $attemptReservation = function () use ($firm, $attemptId, $capturedCents): string {
            try {
                DB::purge();

                $freshAttempt = (new TenantContextService)->runWithFirmContext(
                    $firm,
                    fn () => PaymentAttempt::query()->findOrFail($attemptId),
                );

                app(RefundReservationService::class)->reserve($freshAttempt, $capturedCents);

                return '1';
            } catch (\Throwable) {
                return '0';
            }
        };

        if ($pid === 0) {
            try {
                file_put_contents($childResultFile, $attemptReservation());
            } catch (\Throwable) {
                file_put_contents($childResultFile, '0');
            }

            exit(0);
        }

        try {
            file_put_contents($parentResultFile, $attemptReservation());
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
            'Exactly one of two concurrent full-amount reservations may succeed; got parent='
            .$parentWon.' child='.$childWon.'.'
        );

        // THE INVARIANT, measured directly from the database.
        $held = (int) $tenant->runWithFirmContext($firm, fn () => DB::table('payment_refunds')
            ->where('payment_attempt_id', $attemptId)
            ->whereIn('state', PaymentRefundState::capacityHoldingValues())
            ->sum('amount_cents'));

        $this->assertLessThanOrEqual(
            $capturedCents,
            $held,
            'successful refunds + active reservations must never exceed the captured amount.'
        );

        $this->assertSame(
            $capturedCents,
            $held,
            'The single winning reservation must hold exactly the captured amount.'
        );

        $reservationCount = (int) $tenant->runWithFirmContext($firm, fn () => DB::table('payment_refunds')
            ->where('payment_attempt_id', $attemptId)
            ->whereIn('state', PaymentRefundState::capacityHoldingValues())
            ->count());

        $this->assertSame(1, $reservationCount, 'Two workers must not both reserve the same money.');
    }

    /**
     * FV-A2-052 — the locking mechanism is demonstrated explicitly, not
     * merely implied by the outcome above.
     */
    public function test_fv_a2_052_reservation_uses_a_real_row_lock_on_the_parent_attempt(): void
    {
        $source = file_get_contents(app_path('Services/Pay/RefundReservationService.php'));

        $this->assertIsString($source);

        // The lock must be taken on the PARENT attempt, before the sum
        // is read — the forbidden pattern is read-then-insert with no lock.
        $this->assertMatchesRegularExpression(
            '/PaymentAttempt::query\(\)\s*->whereKey\(.*?\)\s*->lockForUpdate\(\)/s',
            $source,
            'reserve() must take SELECT ... FOR UPDATE on the parent payment_attempts row.'
        );

        $lockPos = strpos($source, 'lockForUpdate()');
        $sumPos = strpos($source, "whereIn('state', PaymentRefundState::capacityHoldingValues())");
        $insertPos = strpos($source, 'PaymentRefund::query()->create(');

        $this->assertIsInt($lockPos);
        $this->assertIsInt($sumPos);
        $this->assertIsInt($insertPos);

        $this->assertLessThan($sumPos, $lockPos, 'The row lock must be acquired BEFORE the held-capacity sum is read.');
        $this->assertLessThan($insertPos, $sumPos, 'The sum must be read before the reservation is inserted.');
    }
}
