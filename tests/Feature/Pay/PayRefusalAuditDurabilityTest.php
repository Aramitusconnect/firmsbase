<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\IdempotencyConflictException;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Services\Pay\PayAuditRecorder;
use App\Services\Pay\ProviderCommandService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    private ?int $firmId = null;

    private ?string $conflictKey = null;

    protected function tearDown(): void
    {
        if ($this->firmId !== null) {
            DB::table('security_events')->where('firm_id', $this->firmId)->delete();
            DB::table('provider_commands')->where('firm_id', $this->firmId)->delete();
            DB::table('firms')->where('id', $this->firmId)->delete();
        }

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
}
