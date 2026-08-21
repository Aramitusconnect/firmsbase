<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DomainEventProcessingStatus;
use App\Enums\DomainEventType;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformAutomationOversightPage;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformAutomationOversightPageTest — Mission 7 ("Super Admin
 * Operational Completion"), items 7.2/7.3. Proves cross-firm visibility
 * for a SuperAdmin over both data sets this page surfaces
 * (`automation_rules` and dead-lettered `domain_events`), and — since
 * both tables carry permanent FORCE ROW LEVEL SECURITY with no
 * cross-firm-read policy — proves this indirectly via the same means
 * every sibling test in this suite uses: firm-scoped rows created in
 * TWO SEPARATE firms both appearing correctly together in one
 * unfiltered read. A naive cross-firm query (no per-firm
 * runWithFirmContext() loop) would see at most one firm's rows (whichever
 * firm's context happened to be active, if any) — never both — so
 * seeing both firms' data at once is direct proof the RLS-safe
 * per-firm-loop pattern (`PlatformAutomationOversightService`) is
 * actually in use, not a naive query.
 */
final class PlatformAutomationOversightPageTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // ------------------------------------------------------------
    // Navigation + authorization
    // ------------------------------------------------------------

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformAutomationOversightPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_security_auditor(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformAutomationOversightPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformAutomationOversightPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformAutomationOversightPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformAutomationOversightPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformAutomationOversightPage::getUrl())->assertOk();
    }

    // ------------------------------------------------------------
    // 7.2 — Automation rules, cross-firm
    // ------------------------------------------------------------

    public function test_automation_rules_from_two_separate_firms_both_appear_in_one_unfiltered_read(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Rules Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Rules Firm B']);

        AutomationRule::factory()->forFirm($firmA)->create(['name' => 'Firm A Only Rule']);
        AutomationRule::factory()->forFirm($firmB)->create(['name' => 'Firm B Only Rule']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformAutomationOversightPage::class);
        $test->assertSuccessful();

        $rows = $test->instance()->getTableRecords();
        $ruleNames = collect($rows)->pluck('rule_name')->all();

        $this->assertContains('Firm A Only Rule', $ruleNames);
        $this->assertContains('Firm B Only Rule', $ruleNames);
    }

    public function test_firm_filter_narrows_the_per_firm_loop_to_exactly_one_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Filtered Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Filtered Firm B']);

        AutomationRule::factory()->forFirm($firmA)->create(['name' => 'Only Visible When Filtered']);
        AutomationRule::factory()->forFirm($firmB)->create(['name' => 'Hidden When Filtered']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformAutomationOversightPage::class);
        $test->filterTable('firm_uuid', $firmA->uuid);

        $rows = $test->instance()->getTableRecords();
        $ruleNames = collect($rows)->pluck('rule_name')->all();

        $this->assertContains('Only Visible When Filtered', $ruleNames);
        $this->assertNotContains('Hidden When Filtered', $ruleNames);
    }

    public function test_last_execution_status_and_failed_action_count_are_computed_from_real_rows(): void
    {
        $firm = Firm::factory()->create();

        $rule = AutomationRule::factory()->forFirm($firm)->create([
            'name' => 'Computed Fields Rule',
            'event_type' => DomainEventType::PaymentAllocationPending,
        ]);

        $olderEvent = DomainEvent::factory()->forFirm($firm)->create();
        $newerEvent = DomainEvent::factory()->forFirm($firm)->create();

        $olderExecution = AutomationExecution::factory()->forFirm($firm)->create([
            'automation_rule_id' => $rule->id,
            'domain_event_id' => $olderEvent->id,
            'status' => AutomationExecutionStatus::Failed,
            'created_at' => now()->subHours(2),
        ]);
        $newerExecution = AutomationExecution::factory()->forFirm($firm)->create([
            'automation_rule_id' => $rule->id,
            'domain_event_id' => $newerEvent->id,
            'status' => AutomationExecutionStatus::Completed,
            'created_at' => now(),
        ]);

        // Two failed actions on the older execution, one on the newer —
        // failed_action_count must sum across every execution of this
        // rule (3), never just the most recent execution's own count.
        AutomationActionExecution::factory()->forFirm($firm)->create([
            'automation_execution_id' => $olderExecution->id,
            'status' => AutomationActionExecutionStatus::Failed,
        ]);
        AutomationActionExecution::factory()->forFirm($firm)->create([
            'automation_execution_id' => $olderExecution->id,
            'status' => AutomationActionExecutionStatus::Failed,
        ]);
        AutomationActionExecution::factory()->forFirm($firm)->create([
            'automation_execution_id' => $newerExecution->id,
            'status' => AutomationActionExecutionStatus::Failed,
        ]);
        // A succeeded action must never be counted.
        AutomationActionExecution::factory()->forFirm($firm)->create([
            'automation_execution_id' => $newerExecution->id,
            'status' => AutomationActionExecutionStatus::Succeeded,
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformAutomationOversightPage::class);
        $rows = collect($test->instance()->getTableRecords())->values();
        $row = $rows->firstWhere('rule_name', 'Computed Fields Rule');

        $this->assertNotNull($row);
        // "Last execution" is the most recently created one — Completed,
        // not the older Failed one.
        $this->assertSame(AutomationExecutionStatus::Completed->value, $row['last_execution_status']);
        $this->assertSame(3, $row['failed_action_count']);
    }

    // ------------------------------------------------------------
    // 7.3 — Dead-lettered domain events, cross-firm
    // ------------------------------------------------------------

    public function test_dead_lettered_domain_events_from_two_separate_firms_both_appear(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Dead Letter Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Dead Letter Firm B']);

        DomainEvent::factory()->forFirm($firmA)->create([
            'processing_status' => DomainEventProcessingStatus::DeadLettered,
            'dead_lettered_at' => now(),
            'last_error' => 'firm A failure reason',
        ]);
        DomainEvent::factory()->forFirm($firmB)->create([
            'processing_status' => DomainEventProcessingStatus::DeadLettered,
            'dead_lettered_at' => now(),
            'last_error' => 'firm B failure reason',
        ]);
        // A non-dead-lettered event must never appear in the dead-letter
        // section, even for a firm that otherwise has dead letters.
        DomainEvent::factory()->forFirm($firmA)->create([
            'processing_status' => DomainEventProcessingStatus::Pending,
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformAutomationOversightPage::getUrl());

        $response->assertOk();
        $response->assertSee('Dead Letter Firm A');
        $response->assertSee('firm A failure reason');
        $response->assertSee('Dead Letter Firm B');
        $response->assertSee('firm B failure reason');
    }

    public function test_no_dead_lettered_events_shows_the_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformAutomationOversightPage::getUrl());

        $response->assertOk();
        $response->assertSee('No dead-lettered domain events.');
    }

    // ------------------------------------------------------------
    // Read-only discipline
    // ------------------------------------------------------------

    public function test_no_requeue_or_force_execute_action_is_offered(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformAutomationOversightPage::getUrl());

        $response->assertOk();
        $response->assertDontSee('Requeue');
        $response->assertDontSee('Force Execute');
        $response->assertDontSee('Retry');
    }
}
