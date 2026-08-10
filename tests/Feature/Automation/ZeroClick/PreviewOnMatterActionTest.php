<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\AutomationRuleResource\Actions\PreviewOnMatterAction;
use App\Filament\Firm\Resources\AutomationRuleResource\Pages\ListAutomationRules;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PreviewOnMatterActionTest — Zero-Click Core Workflow Automation, test
 * matrix W/Y. Proves the new Preview row action is only offered for
 * matter_opened rules, and that invoking it never mutates any record
 * (mirrors WorkflowPreviewServiceTest's own non-mutation proof, this
 * time through the real Filament action).
 */
final class PreviewOnMatterActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
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

    public function test_preview_action_is_offered_for_a_matter_opened_rule_and_never_mutates_state(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        [$rule, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->create([
                'conditions_json' => [],
                'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'X', 'assigned_to' => 'role:firm_owner']]],
            ]);
            $matter = Matter::factory()->forFirm($firm)->create();

            return [$rule, $matter];
        });

        $taskCountBefore = $this->runWithFirmContext($firm, fn () => Task::query()->count());

        $this->runWithFirmContext($firm, function () use ($rule, $matter) {
            Livewire::test(ListAutomationRules::class)
                ->callTableAction(PreviewOnMatterAction::getDefaultName(), $rule, data: ['matter_id' => $matter->id])
                ->assertOk();
        });

        $taskCountAfter = $this->runWithFirmContext($firm, fn () => Task::query()->count());

        $this->assertSame($taskCountBefore, $taskCountAfter);
    }

    public function test_preview_action_is_not_offered_for_a_non_matter_opened_rule(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $rule = $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::InvoiceOverdue)->create());

        $this->runWithFirmContext($firm, function () use ($rule) {
            Livewire::test(ListAutomationRules::class)
                ->assertTableActionHidden(PreviewOnMatterAction::getDefaultName(), $rule);
        });
    }
}
