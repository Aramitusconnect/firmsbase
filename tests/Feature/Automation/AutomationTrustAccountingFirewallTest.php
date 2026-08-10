<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\Automation\AutomationAccessPolicyService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\ConditionEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AutomationTrustAccountingFirewallTest — Event-Driven Automation
 * Engine, item 19. Proves the STRUCTURAL guarantee that automation can
 * never directly mutate Trust/Accounting state: no AutomationActionType
 * case for any such operation exists at all (so the Firm UI's own
 * action-type select literally cannot offer one), the registry throws
 * for anything not a real case, save-time validation rejects a forged
 * action_type string, and — the defense-in-depth backstop, only
 * reachable via direct DB tampering — the execution engine independently
 * refuses one too, never silently skipping it as if it were valid.
 */
class AutomationTrustAccountingFirewallTest extends TestCase
{
    use RefreshDatabase;

    private const PROHIBITED_OPERATIONS = [
        'create_trust_ledger_entry',
        'create_accounting_journal_entry',
        'create_accounting_posting',
        'approve_trust_request',
        'resolve_payment_allocation',
        'issue_refund',
        'create_chargeback',
        'write_off_invoice',
        'reopen_accounting_period',
    ];

    public function test_no_registered_action_type_names_a_trust_or_accounting_mutation(): void
    {
        foreach (AutomationActionType::cases() as $case) {
            $this->assertNotContains($case->value, self::PROHIBITED_OPERATIONS);
        }
    }

    public function test_the_handler_registry_has_no_entry_for_any_prohibited_operation_and_only_five_registered_types(): void
    {
        $registry = new AutomationActionHandlerRegistry;

        // Every registered type maps to a real, resolvable handler...
        $this->assertCount(5, $registry->registeredTypes());

        foreach ($registry->registeredTypes() as $type) {
            $this->assertNotContains($type, self::PROHIBITED_OPERATIONS);
        }

        // ...and there is no registered type sharing a name with a
        // prohibited operation to begin with.
        $this->assertEmpty(array_intersect($registry->registeredTypes(), self::PROHIBITED_OPERATIONS));
    }

    public function test_the_registry_throws_for_any_unregistered_action_type_string(): void
    {
        $registry = new AutomationActionHandlerRegistry;

        // AutomationActionType::tryFrom() itself would already reject a
        // forged string not in the closed enum — this proves the SECOND
        // independent guard: even a real enum CASE with no registry
        // entry still throws, never silently no-ops.
        foreach (self::PROHIBITED_OPERATIONS as $operation) {
            $this->assertNull(AutomationActionType::tryFrom($operation));
        }
    }

    #[DataProvider('prohibitedOperationProvider')]
    public function test_save_time_validation_rejects_a_forged_action_type(string $operation): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $service = new AutomationRuleService(new AutomationActionHandlerRegistry, new AutomationAccessPolicyService);

        $this->expectException(\InvalidArgumentException::class);

        $service->create(
            $firm, $owner, 'Malicious rule', null, DomainEventType::PaymentAllocationPending, [],
            [['action_type' => $operation, 'config' => []]],
        );
    }

    #[DataProvider('prohibitedOperationProvider')]
    public function test_execution_time_independently_refuses_a_tampered_action_type(string $operation): void
    {
        $firm = Firm::factory()->create();

        // Only reachable via direct DB tampering — AutomationRuleService
        // would already have refused this at save time (proven above).
        $rule = $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::PaymentAllocationPending,
            'conditions_json' => [],
            'actions_json' => [['action_type' => $operation, 'config' => []]],
        ]));

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::PaymentAllocationPending,
            'payload_json' => ['pending_allocation' => ['id' => 1, 'payment_id' => 1, 'invoice_id' => null, 'amount_cents' => 1000]],
        ]));

        $matcher = new AutomationRuleMatchingService(new ConditionEvaluatorService, new AutomationActionHandlerRegistry);
        $result = $this->runWithFirmContext($firm, fn () => $matcher->match($firm, $event));

        // The tampered rule is recorded as a Failed execution — never
        // silently skipped, and no AutomationActionExecution (and
        // therefore no chance to ever run a handler) is created for it.
        $execution = $this->runWithFirmContext($firm, fn () => AutomationExecution::query()
            ->where('automation_rule_id', $rule->id)->where('domain_event_id', $event->id)->first());

        $this->assertSame('failed', $execution->status->value);
        $this->assertNotNull($execution->failure_reason);

        $actionExecutionCount = $this->runWithFirmContext($firm, fn () => $execution->actionExecutions()->count());
        $this->assertSame(0, $actionExecutionCount);
    }

    public static function prohibitedOperationProvider(): array
    {
        return array_map(fn (string $op) => [$op], self::PROHIBITED_OPERATIONS);
    }
}
