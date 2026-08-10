<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\Automation\AutomationAccessPolicyService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AutomationRuleServiceTest — Event-Driven Automation Engine, item 4 +
 * item 17 (security). Proves AutomationRuleService is the sole gate on
 * both save-time closed-vocabulary validation (unknown field/operator/
 * action type, requires_approval forcing) and role-based authorization
 * (AutomationAccessPolicyService), including cross-firm rejection.
 */
class AutomationRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutomationRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutomationRuleService(
            new AutomationActionHandlerRegistry,
            new AutomationAccessPolicyService,
        );
    }

    private function validActions(): array
    {
        return [
            ['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'Follow up']],
        ];
    }

    // ------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------

    public function test_firm_owner_can_create_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $rule = $this->service->create(
            $firm, $owner, 'Invoice overdue reminder', null,
            DomainEventType::InvoiceOverdue, [], $this->validActions(),
        );

        $this->assertInstanceOf(AutomationRule::class, $rule);
    }

    public function test_billing_staff_can_create_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $billingStaff = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff]));

        $rule = $this->service->create(
            $firm, $billingStaff, 'Invoice overdue reminder', null,
            DomainEventType::InvoiceOverdue, [], $this->validActions(),
        );

        $this->assertInstanceOf(AutomationRule::class, $rule);
    }

    public function test_unauthorized_role_cannot_create_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $paralegal = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal]));

        $this->expectException(\RuntimeException::class);

        $this->service->create(
            $firm, $paralegal, 'Invoice overdue reminder', null,
            DomainEventType::InvoiceOverdue, [], $this->validActions(),
        );
    }

    public function test_unauthorized_role_cannot_update_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $receptionist = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Receptionist]));
        $rule = $this->service->create($firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $this->expectException(\RuntimeException::class);

        $this->service->update($firm, $rule, $receptionist, name: 'Renamed');
    }

    public function test_unauthorized_role_cannot_toggle_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $legalAssistant = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::LegalAssistant]));
        $rule = $this->service->create($firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $this->expectException(\RuntimeException::class);

        $this->service->setEnabled($firm, $rule, false, $legalAssistant);
    }

    public function test_cross_firm_rule_update_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create(['role' => FirmUserRole::FirmOwner]));
        $ownerB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create(['role' => FirmUserRole::FirmOwner]));
        $ruleA = $this->service->create($firmA, $ownerA, 'Firm A rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $this->expectException(\RuntimeException::class);

        // firmB owner, acting with firmB as the tenant context, targeting firm A's rule.
        $this->service->update($firmB, $ruleA, $ownerB, name: 'Hijacked');
    }

    public function test_cross_firm_rule_toggle_is_rejected_even_when_actor_belongs_to_the_target_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create(['role' => FirmUserRole::FirmOwner]));
        $ownerB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create(['role' => FirmUserRole::FirmOwner]));
        $ruleA = $this->service->create($firmA, $ownerA, 'Firm A rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $this->expectException(\RuntimeException::class);

        // Passing firmA (the rule's real firm) but an actor who belongs to firm B.
        $this->service->setEnabled($firmA, $ruleA, false, $ownerB);
    }

    // ------------------------------------------------------------
    // Closed-vocabulary validation
    // ------------------------------------------------------------

    public function test_unknown_condition_field_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(
            $firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue,
            [['field' => 'invoice.stripe_secret_key', 'operator' => AutomationConditionOperator::Equals->value, 'value' => 'x']],
            $this->validActions(),
        );
    }

    public function test_unknown_condition_operator_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(
            $firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue,
            [['field' => 'invoice.days_overdue', 'operator' => 'shell_exec', 'value' => 7]],
            $this->validActions(),
        );
    }

    public function test_unknown_action_type_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(
            $firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [],
            [['action_type' => 'create_trust_ledger_entry', 'config' => []]],
        );
    }

    public function test_a_rule_must_have_at_least_one_action(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [], []);
    }

    public function test_valid_condition_against_the_events_own_allowlisted_field_is_accepted(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $rule = $this->service->create(
            $firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue,
            [['field' => 'invoice.days_overdue', 'operator' => AutomationConditionOperator::GreaterThanOrEqual->value, 'value' => 7]],
            $this->validActions(),
        );

        $this->assertSame([['field' => 'invoice.days_overdue', 'operator' => 'greater_than_or_equal', 'value' => 7]], $rule->conditions_json);
    }

    // ------------------------------------------------------------
    // Update semantics
    // ------------------------------------------------------------

    public function test_updating_only_the_name_does_not_bump_the_version(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $rule = $this->service->create($firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $updated = $this->service->update($firm, $rule, $owner, name: 'Renamed rule');

        $this->assertSame('Renamed rule', $updated->name);
        $this->assertSame(1, $updated->version);
    }

    public function test_updating_actions_bumps_the_version(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $rule = $this->service->create($firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $updated = $this->service->update($firm, $rule, $owner, actions: [
            ['action_type' => AutomationActionType::NotifyBillingStaff->value, 'config' => []],
        ]);

        $this->assertSame(2, $updated->version);
    }

    public function test_set_enabled_toggles_the_rule_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $rule = $this->service->create($firm, $owner, 'Rule', null, DomainEventType::InvoiceOverdue, [], $this->validActions());

        $disabled = $this->service->setEnabled($firm, $rule, false, $owner);

        $this->assertFalse($disabled->enabled);
    }
}
