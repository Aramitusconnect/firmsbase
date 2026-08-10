<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\AutomationRuleResource;
use App\Filament\Firm\Resources\AutomationRuleResource\Pages\ListAutomationRules;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AutomationRuleResourceAccessTest — Event-Driven Automation Engine,
 * item 15/17. Proves AutomationRulePolicy actually closes the
 * ToggleColumn('enabled') bypass: an unauthorized role can neither
 * view the list (so never reaches the toggle at all) nor open the edit
 * page for a rule directly by URL, while an authorized role can do
 * both — and that cross-firm access to another firm's rule is refused.
 */
final class AutomationRuleResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_an_authorized_role_can_view_the_rules_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListAutomationRules::class));

        $test->assertSuccessful();
    }

    public function test_an_unauthorized_role_cannot_view_the_rules_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationRuleResource::getUrl('index')));

        $response->assertForbidden();
    }

    public function test_an_unauthorized_role_cannot_open_the_edit_page_for_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $rule = $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::InvoiceOverdue,
            'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'x', 'assigned_to' => 'role:billing_staff']]],
        ]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationRuleResource::getUrl('edit', ['record' => $rule])));

        $response->assertForbidden();
    }

    public function test_an_authorized_role_can_open_the_edit_page_for_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $rule = $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::InvoiceOverdue,
            'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'x', 'assigned_to' => 'role:billing_staff']]],
        ]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationRuleResource::getUrl('edit', ['record' => $rule])));

        $response->assertSuccessful();
    }

    public function test_cross_firm_edit_access_to_another_firms_rule_is_refused(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $ruleB = $this->runWithFirmContext($firmB, fn () => AutomationRule::factory()->forFirm($firmB)->create([
            'event_type' => DomainEventType::InvoiceOverdue,
            'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'x', 'assigned_to' => 'role:billing_staff']]],
        ]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(AutomationRuleResource::getUrl('edit', ['record' => $ruleB])));

        $response->assertNotFound();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
