<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages\ListMatterBudgetTemplates;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterBudgetTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class MatterBudgetTemplateResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_an_authorized_role_can_view_the_templates_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListMatterBudgetTemplates::class));

        $test->assertSuccessful();
    }

    public function test_an_unauthorized_role_cannot_view_the_templates_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterBudgetTemplateResource::getUrl('index')));

        $response->assertForbidden();
    }

    public function test_an_unauthorized_role_cannot_open_the_edit_page(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterBudgetTemplateResource::getUrl('edit', ['record' => $template])));

        $response->assertForbidden();
    }

    public function test_an_authorized_role_can_open_the_edit_page(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterBudgetTemplateResource::getUrl('edit', ['record' => $template])));

        $response->assertSuccessful();
    }

    public function test_cross_firm_edit_access_is_refused(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $templateB = $this->runWithFirmContext($firmB, fn () => MatterBudgetTemplate::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(MatterBudgetTemplateResource::getUrl('edit', ['record' => $templateB])));

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
