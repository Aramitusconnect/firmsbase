<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\IdempotencyConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\ProviderCommand;
use App\Services\Pay\PayAuditRecorder;
use App\Services\Pay\ProviderCommandService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Pay\Concerns\CleansUpDurablePayAudit;
use Tests\TestCase;

/**
 * FV-A2-001 … FV-A2-007 — Gate A2 infrastructure. CERTIFICATION BLOCKING.
 *
 * Proves the compatibility decision is real code, not a document: the
 * existing IntegrationProvider/FirmIntegration architecture is reused
 * (no parallel provider-account subsystem), the ProviderCommand envelope
 * is immutable, and idempotency is enforced by the DATABASE.
 */
class ProviderCommandInfrastructureTest extends TestCase
{
    use CleansUpDurablePayAudit;
    use RefreshDatabase;

    private function commands(): ProviderCommandService
    {
        return app(ProviderCommandService::class);
    }

    /**
     * FV-A2-001 — the existing provider architecture is mapped, not
     * duplicated. No parallel provider-account subsystem exists.
     */
    public function test_fv_a2_001_no_duplicate_provider_account_subsystem_was_created(): void
    {
        foreach (['provider_platform_connections', 'firm_provider_accounts', 'provider_resource_locators', 'provider_resource_mappings'] as $forbidden) {
            $this->assertFalse(
                Schema::hasTable($forbidden),
                "Gate A2 must NOT create a parallel [{$forbidden}] table: the architecture roles "
                .'ProviderPlatformConnection / FirmProviderAccount map onto the EXISTING '
                .'integration_providers / firm_integrations tables (v1.4 §4).'
            );
        }

        // And the roles are genuinely filled by the existing tables.
        $this->assertTrue(Schema::hasTable('integration_providers'));
        $this->assertTrue(Schema::hasTable('firm_integrations'));

        // provider_commands points at both of them.
        $this->assertTrue(Schema::hasColumn('provider_commands', 'integration_provider_id'));
        $this->assertTrue(Schema::hasColumn('provider_commands', 'firm_integration_id'));
    }

    /**
     * FV-A2-002 — the provider_operation_attempts compatibility
     * decision. It is REUSED unchanged as the at-most-once send gate,
     * and provider_commands derives its key deterministically. No second
     * command engine was built.
     */
    public function test_fv_a2_002_existing_at_most_once_gate_is_reused_not_replaced(): void
    {
        // The existing engine still exists, untouched, with its
        // Global/EXEMPT (no-RLS) posture intact.
        $this->assertTrue(Schema::hasTable('provider_operation_attempts'));

        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'provider_operation_attempts'");
        $this->assertFalse(
            (bool) $row->relrowsecurity,
            'provider_operation_attempts must remain Global/EXEMPT — Gate A2 did not change its security posture.'
        );

        // And a ProviderCommand yields a deterministic, stable key for it.
        $firm = Firm::factory()->create();
        $command = $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create());

        $this->assertSame('fvpay:'.$command->uuid, $command->logicalOperationKey());

        // Re-reading requires tenant context (provider_commands is
        // FORCE RLS) — the key must be identical across reads.
        $reread = $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->findOrFail($command->id));
        $this->assertSame(
            $command->logicalOperationKey(),
            $reread->logicalOperationKey(),
            'The gate key must be deterministic — the same command always maps to the same gate row.'
        );
    }

    /** FV-A2-003 — the immutable envelope really is immutable. */
    public function test_fv_a2_003_provider_command_envelope_is_immutable(): void
    {
        $firm = Firm::factory()->create();
        $command = $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create());

        foreach (['idempotency_key' => 'tampered', 'canonical_payload_hash' => str_repeat('b', 64), 'aggregate_id' => 999] as $field => $value) {
            try {
                $this->runWithFirmContext($firm, fn () => $command->update([$field => $value]));
                $this->fail("Changing the immutable envelope field [{$field}] must be refused.");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('envelope is immutable', $e->getMessage());
            }
        }
    }

    /** FV-A2-003 — execution metadata IS mutable, or nothing could progress. */
    public function test_fv_a2_003_execution_metadata_remains_mutable(): void
    {
        $firm = Firm::factory()->create();
        $command = $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create());

        $dispatched = $this->commands()->transition($command, ProviderCommandStatus::Dispatched);

        $this->assertSame(ProviderCommandStatus::Dispatched, $dispatched->status);
        $this->assertNotNull($dispatched->submitted_at);
    }

    /** FV-A2-004 — same key + same payload is a safe, silent reuse. */
    public function test_fv_a2_004_same_key_same_payload_returns_the_same_logical_command(): void
    {
        $firm = Firm::factory()->create();
        $payload = ['amount_cents' => 5000, 'currency' => 'USD'];

        $first = $this->runWithFirmContext($firm, fn () => $this->commands()->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: 'capture:test:same',
            canonicalPayload: $payload,
        ));

        // Key order differs — same economic instruction.
        $second = $this->runWithFirmContext($firm, fn () => $this->commands()->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: 'capture:test:same',
            canonicalPayload: ['currency' => 'USD', 'amount_cents' => 5000],
        ));

        $this->assertSame($first->id, $second->id, 'A safe retry must reuse the SAME logical command.');
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->count()));
    }

    /** FV-A2-005 — same key + different payload is blocked, with no execution. */
    public function test_fv_a2_005_same_key_different_payload_is_blocked_and_audited(): void
    {
        $firm = Firm::factory()->create();

        $original = $this->runWithFirmContext($firm, fn () => $this->commands()->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: 'capture:test:conflict',
            canonicalPayload: ['amount_cents' => 5000, 'currency' => 'USD'],
        ));

        try {
            $this->runWithFirmContext($firm, fn () => $this->commands()->createOrReuse(
                firmId: (int) $firm->id,
                commandType: ProviderCommandType::CapturePayment,
                aggregateType: PaymentAttempt::class,
                aggregateId: 1,
                idempotencyKey: 'capture:test:conflict',
                // A DIFFERENT amount under the same key.
                canonicalPayload: ['amount_cents' => 999999, 'currency' => 'USD'],
            ));
            $this->fail('Reusing an idempotency key with a different payload must raise IdempotencyConflictException.');
        } catch (IdempotencyConflictException $e) {
            $this->assertSame('capture:test:conflict', $e->idempotencyKey);
        }

        // No second command was created — nothing to execute.
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->count()));
        $this->assertSame(
            $original->canonical_payload_hash,
            $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->firstOrFail()->canonical_payload_hash),
            'The original economic instruction must be untouched by a conflicting request.'
        );

        // Auditing of this refusal is deliberately NOT asserted here.
        // PayAuditRecorder writes refusal events on an independent
        // connection so they survive the rollback that follows them, and
        // under RefreshDatabase the firm row is not yet committed, so
        // that write cannot satisfy security_events' foreign key and
        // falls back to the ambient connection — which then shares this
        // test transaction's fate. Asserting a count here would be
        // asserting the fallback, not the guarantee.
        //
        // The real guarantee — the audit SURVIVES the rollback — is
        // proved against committed fixtures by
        // Tests\Feature\Pay\PayRefusalAuditDurabilityTest.
    }

    /** FV-A2-005 — the database, not the application, owns the key. */
    public function test_fv_a2_005_idempotency_key_uniqueness_is_enforced_by_the_database(): void
    {
        $firm = Firm::factory()->create();

        $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create([
            'idempotency_key' => 'db:enforced:key',
        ]));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->runWithFirmContext($firm, fn () => DB::table('provider_commands')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'command_type' => 'capture_payment',
            'aggregate_type' => 'X',
            'aggregate_id' => 2,
            'idempotency_key' => 'db:enforced:key',
            'canonical_payload_hash' => str_repeat('c', 64),
            'canonical_payload' => '{}',
            'correlation_id' => (string) Str::uuid(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * FV-A2-005 — the same idempotency key in a DIFFERENT firm is not a
     * conflict. Keys are tenant-scoped, so one firm can never block or
     * observe another firm's key.
     */
    public function test_fv_a2_005_idempotency_keys_are_scoped_per_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $a = $this->runWithFirmContext($firmA, fn () => $this->commands()->createOrReuse(
            firmId: (int) $firmA->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: 'shared:key',
            canonicalPayload: ['amount_cents' => 100],
        ));

        $b = $this->runWithFirmContext($firmB, fn () => $this->commands()->createOrReuse(
            firmId: (int) $firmB->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 1,
            idempotencyKey: 'shared:key',
            canonicalPayload: ['amount_cents' => 200],
        ));

        $this->assertNotSame($a->id, $b->id);
    }

    /** An OUTCOME_UNKNOWN command always requires reconciliation. */
    public function test_outcome_unknown_command_always_requires_reconciliation(): void
    {
        $firm = Firm::factory()->create();
        $command = $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create());

        $dispatched = $this->commands()->transition($command, ProviderCommandStatus::Dispatched);
        $unknown = $this->commands()->transition($dispatched, ProviderCommandStatus::OutcomeUnknown);

        $this->assertSame(ProviderCommandStatus::OutcomeUnknown, $unknown->status);
        $this->assertTrue((bool) $unknown->reconciliation_required);
        $this->assertTrue($unknown->status->isTerminal(), 'OUTCOME_UNKNOWN has no automated way out.');
    }

    /** The transition matrix is enforced, not advisory. */
    public function test_illegal_command_transitions_are_refused(): void
    {
        $firm = Firm::factory()->create();
        $command = $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Illegal provider command transition/');

        // pending -> succeeded skips dispatch entirely.
        $this->commands()->transition($command, ProviderCommandStatus::Succeeded);
    }

    /** An economic instruction can never be deleted. */
    public function test_provider_command_cannot_be_deleted(): void
    {
        $firm = Firm::factory()->create();
        $command = $this->createWithFirmContext($firm, fn () => ProviderCommand::factory()->forFirm($firm)->create());

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $command->delete());
    }
}
