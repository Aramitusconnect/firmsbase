<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\IdempotencyConflictException;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\SecurityEvent;
use App\Services\Pay\PayAuditRecorder;
use App\Services\Pay\ProviderCommandService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pay\Concerns\CleansUpPayAuditFixtures;
use Tests\TestCase;

/**
 * Proves the audit trail of a REFUSAL survives the rollback of the
 * transaction whose failure it records (v1.4 §43).
 *
 * This matters because every refusal event is raised immediately before
 * an exception, and that exception rolls the caller's transaction back.
 * An audit row written on the ambient connection would be erased along
 * with it — the audit of the single most security-relevant event would
 * be the one guaranteed to disappear. This was OBSERVED during Gate A2
 * development, not theorized: an idempotency-conflict audit row vanished
 * with the rolled-back transaction that raised it, which is why
 * PayAuditRecorder now writes refusal events on the independent
 * `pgsql_audit` connection (the same mechanism
 * App\Services\TimelineEventRecorder already uses).
 *
 * NO RefreshDatabase, deliberately: an independent connection can only
 * satisfy security_events' foreign key against a firm row that is really
 * COMMITTED. Fixtures are created and explicitly cleaned up here.
 */
class PayRefusalAuditDurabilityTest extends TestCase
{
    use CleansUpPayAuditFixtures;

    private ?int $firmId = null;

    private ?string $conflictKey = null;

    protected function tearDown(): void
    {
        // Order is load-bearing: purge the durable Pay audit rows while
        // they are still attributed to this firm, THEN delete the firm.
        // Reversing these two makes the rows unidentifiable and
        // unremovable forever (ON DELETE SET NULL + no DELETE policy).
        $this->purgeAuditFixturesForFirms([$this->firmId]);

        if ($this->firmId !== null) {
            DB::table('provider_commands')->where('firm_id', $this->firmId)->delete();
            DB::table('firms')->where('id', $this->firmId)->delete();
        }

        $this->assertNoOrphanedPayAuditRows();

        parent::tearDown();
    }

    public function test_idempotency_conflict_audit_survives_the_rollback_that_follows_it(): void
    {
        $firm = Firm::factory()->create();
        $this->firmId = (int) $firm->id;

        $tenant = new TenantContextService;
        $commands = app(ProviderCommandService::class);
        $this->conflictKey = 'durable:conflict:'.Str::random(10);

        // Establish the original economic instruction (committed).
        $tenant->runWithFirmContext($firm, fn () => $commands->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: $this->conflictKey,
            canonicalPayload: ['amount_cents' => 5000, 'currency' => 'USD'],
        ));

        // security_events is FORCE RLS, so every read below must run
        // under the firm's context — a contextless read correctly
        // returns nothing and would silently pass a broken assertion.
        $countConflictAudits = fn (): int => (int) $tenant->runWithFirmContext(
            $firm,
            fn () => DB::table('security_events')
                ->where('firm_id', $firm->id)
                ->where('event_type', PayAuditRecorder::IDEMPOTENCY_CONFLICT)
                ->count(),
        );

        $auditedBefore = $countConflictAudits();

        $this->assertSame(0, $auditedBefore);

        // Now provoke a conflict INSIDE an explicit transaction, and let
        // that transaction roll back — exactly what happens in
        // production when the domain exception propagates.
        $threw = false;

        try {
            $tenant->runWithFirmContext($firm, fn () => DB::transaction(function () use ($commands, $firm) {
                $commands->createOrReuse(
                    firmId: (int) $firm->id,
                    commandType: ProviderCommandType::CapturePayment,
                    aggregateType: PaymentAttempt::class,
                    aggregateId: 1,
                    idempotencyKey: $this->conflictKey,
                    // A DIFFERENT economic instruction under the same key.
                    canonicalPayload: ['amount_cents' => 999999, 'currency' => 'USD'],
                );
            }));
        } catch (IdempotencyConflictException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The conflicting instruction must be refused.');

        // THE POINT OF THIS TEST: the audit row is still there, even
        // though the transaction that produced it rolled back.
        $auditedAfter = $countConflictAudits();

        $this->assertSame(
            1,
            $auditedAfter,
            'A refusal audit MUST survive the rollback of the transaction whose failure it records.'
        );

        // And no second economic instruction was created.
        $this->assertSame(
            1,
            (int) $tenant->runWithFirmContext(
                $firm,
                fn () => DB::table('provider_commands')->where('firm_id', $firm->id)->count(),
            ),
            'A refused conflicting request must never create a second provider command.'
        );
    }

    /**
     * GATE A2 REMEDIATION #1 REGRESSION. Proves all four required
     * properties of the durable-audit isolation fix in one place:
     *
     *   1. a durable refusal audit SURVIVES the domain rollback;
     *   2. it stays ATTRIBUTED to its firm (never orphaned), which is
     *      what keeps it out of contextless readers' view — the audit log
     *      has no DELETE policy under FORCE RLS, so an orphaned row could
     *      never be removed;
     *   3. NO firm_id = NULL Pay residue remains afterwards;
     *   4. UNRELATED security events are untouched.
     */
    public function test_durable_pay_audit_stays_attributed_and_leaves_no_contextless_residue(): void
    {
        $firm = Firm::factory()->create();
        $this->firmId = (int) $firm->id;

        $tenant = new TenantContextService;
        $commands = app(ProviderCommandService::class);
        $key = 'remediation:conflict:'.Str::random(10);

        // An UNRELATED audit event, owned by the same firm but written by
        // a different category — it must survive this test untouched.
        $unrelated = $tenant->runWithFirmContext($firm, fn () => SecurityEvent::query()->create([
            'firm_id' => $firm->id,
            'actor_type' => 'system',
            'event_type' => 'unrelated.probe',
            'category' => 'authentication',
        ]));

        $tenant->runWithFirmContext($firm, fn () => $commands->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: $key,
            canonicalPayload: ['amount_cents' => 1000, 'currency' => 'USD'],
        ));

        // Provoke the refusal inside a transaction that then rolls back.
        try {
            $tenant->runWithFirmContext($firm, fn () => DB::transaction(function () use ($commands, $firm, $key) {
                $commands->createOrReuse(
                    firmId: (int) $firm->id,
                    commandType: ProviderCommandType::CapturePayment,
                    aggregateType: PaymentAttempt::class,
                    aggregateId: 1,
                    idempotencyKey: $key,
                    canonicalPayload: ['amount_cents' => 999999, 'currency' => 'USD'],
                );
            }));
            $this->fail('The conflicting instruction must be refused.');
        } catch (IdempotencyConflictException) {
            // expected
        }

        // (1) The refusal audit survived the rollback.
        $survived = (int) $tenant->runWithFirmContext($firm, fn () => DB::table('security_events')
            ->where('firm_id', $firm->id)
            ->where('event_type', PayAuditRecorder::IDEMPOTENCY_CONFLICT)
            ->count());

        $this->assertSame(1, $survived, 'A refusal audit must survive the rollback of the transaction that raised it.');

        // (2) It is still attributed to its firm — never orphaned.
        $orphanedForThisFirm = (int) $tenant->runWithFirmContext($firm, fn () => DB::table('security_events')
            ->where('category', PayAuditRecorder::CATEGORY)
            ->whereNull('firm_id')
            ->count());

        $this->assertSame(0, $orphanedForThisFirm, 'A durable Pay audit row must remain attributed to its firm.');

        // (3) No contextless residue: a reader with NO tenant context can
        // see only firm_id IS NULL rows, and there must be none.
        $this->assertSame(
            0,
            DB::table('security_events')->where('category', PayAuditRecorder::CATEGORY)->count(),
            'A contextless reader must see no FirmsVault Pay audit rows at all.'
        );

        // (4) The unrelated event is untouched.
        $this->assertSame(
            1,
            (int) $tenant->runWithFirmContext($firm, fn () => DB::table('security_events')
                ->where('id', $unrelated->id)
                ->where('category', 'authentication')
                ->count()),
            'Pay audit handling must never disturb unrelated security events.'
        );
    }
}
