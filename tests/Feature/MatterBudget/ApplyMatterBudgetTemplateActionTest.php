<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource\Actions\ApplyMatterBudgetTemplateAction;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterBudget;
use App\Models\MatterBudgetTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ApplyMatterBudgetTemplateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_applying_a_template_through_the_ui_creates_a_matter_budget(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create([
            'name' => 'Immigration AOS', 'expected_hours_json' => ['attorney' => 8],
        ]));

        $this->runWithFirmContext($firm, function () use ($matter, $template) {
            Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
                ->mountAction(ApplyMatterBudgetTemplateAction::getDefaultName(), arguments: ['record' => $matter->id])
                ->setActionData(['template_id' => $template->id])
                ->callMountedAction();
        });

        $budget = $this->runWithFirmContext($firm, fn () => MatterBudget::query()->where('matter_id', $matter->id)->first());

        $this->assertNotNull($budget);
        $this->assertSame($template->id, $budget->source_template_id);
        $this->assertEquals(8, $budget->expected_hours_json['attorney']);
    }

    public function test_the_action_is_not_visible_to_an_unauthorized_role(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $this->runWithFirmContext($firm, function () use ($matter) {
            $test = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()]);
            $test->assertActionHidden(ApplyMatterBudgetTemplateAction::getDefaultName());
        });
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
