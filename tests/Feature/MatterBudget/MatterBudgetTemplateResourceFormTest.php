<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages\CreateMatterBudgetTemplate;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages\EditMatterBudgetTemplate;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterBudgetTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MatterBudgetTemplateResourceFormTest — proves the scalar
 * hours_<role> / expenses_<category> form fields correctly collapse
 * into (on create) and expand from (on edit)
 * expected_hours_json/expected_expenses_json, including the
 * dollars-to-cents conversion for expenses.
 */
final class MatterBudgetTemplateResourceFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_submitting_the_create_form_produces_the_correct_json_shape(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () {
            Livewire::test(CreateMatterBudgetTemplate::class)
                ->fillForm([
                    'name' => 'Immigration AOS',
                    'hours_attorney' => 8,
                    'hours_paralegal' => 15,
                    'expenses_filing_court_costs' => 150,
                    'warning_threshold_percent' => 75,
                    'high_threshold_percent' => 90,
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        });

        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::query()->where('name', 'Immigration AOS')->first());

        $this->assertNotNull($template);
        $this->assertEquals(8, $template->expected_hours_json['attorney']);
        $this->assertEquals(15, $template->expected_hours_json['paralegal']);
        $this->assertArrayNotHasKey('receptionist', $template->expected_hours_json);
        // $150.00 -> 15000 cents.
        $this->assertSame(15000, $template->expected_expenses_json['filing_court_costs']);
    }

    public function test_the_edit_form_is_prefilled_from_the_stored_json(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create([
            'expected_hours_json' => ['attorney' => 6],
            'expected_expenses_json' => ['vendor_expert_costs' => 25000],
        ]));

        $this->runWithFirmContext($firm, function () use ($template) {
            Livewire::test(EditMatterBudgetTemplate::class, ['record' => $template->getRouteKey()])
                ->assertFormSet([
                    'hours_attorney' => 6,
                    'expenses_vendor_expert_costs' => 250.0,
                ]);
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
