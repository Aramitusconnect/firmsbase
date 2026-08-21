<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\FirmLeadStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\ViewFirmLead;
use App\Filament\Firm\Resources\FirmLeadResource\RelationManagers\ConsultationsRelationManager;
use App\Models\Consultation;
use App\Models\ConsultationOutcome;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ConsultationsRelationManagerTest — Mission 5B (5.7). `Consultation`/
 * `ConsultationOutcome` had zero Filament references before this
 * mission; this proves the new tab actually lists/creates/updates
 * real rows, tenant-scoped to the owning lead, and that "Mark Held" is
 * the first real writer of FirmLeadStatus::ConsultationHeld.
 */
final class ConsultationsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_tab_is_visible_for_the_owning_firms_user_and_hidden_for_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id]));

        $this->assertTrue(ConsultationsRelationManager::canViewForRecord($lead, ViewFirmLead::class));

        $otherFirm = Firm::factory()->create();
        $otherLead = $this->runWithFirmContext($otherFirm, fn () => FirmLead::factory()->create(['firm_id' => $otherFirm->id]));

        $this->assertFalse(ConsultationsRelationManager::canViewForRecord($otherLead, ViewFirmLead::class));
    }

    public function test_list_shows_only_this_leads_own_consultations(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $leadA = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id]));
        $leadB = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id]));

        $consultationA = $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($leadA)->create());
        $consultationB = $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($leadB)->create());

        $this->runWithFirmContext($firm, function () use ($leadA, $consultationA, $consultationB): void {
            $test = Livewire::test(ConsultationsRelationManager::class, [
                'ownerRecord' => $leadA,
                'pageClass' => ViewFirmLead::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$consultationA]);
            $test->assertCanNotSeeTableRecords([$consultationB]);
        });
    }

    public function test_schedule_consultation_header_action_creates_a_row_and_advances_a_new_lead(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::New]));
        $scheduledAt = now()->addDays(2);

        $this->runWithFirmContext($firm, function () use ($lead, $scheduledAt): void {
            $test = Livewire::test(ConsultationsRelationManager::class, [
                'ownerRecord' => $lead,
                'pageClass' => ViewFirmLead::class,
            ]);
            $test->mountTableAction('addConsultation');
            $test->setTableActionData([
                'scheduled_at' => $scheduledAt->toDateTimeString(),
                'notes' => 'First meeting',
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Consultation scheduled');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLead::query()->find($lead->id));
        $this->assertSame(FirmLeadStatus::ConsultationScheduled, $fresh->status);

        $consultation = $this->runWithFirmContext($firm, fn () => Consultation::query()->where('firm_lead_id', $lead->id)->first());
        $this->assertNotNull($consultation);
        $this->assertSame('First meeting', $consultation->notes);
    }

    public function test_mark_held_action_records_outcome_and_advances_lead_to_consultation_held(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::ConsultationScheduled]));
        $consultation = $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($lead)->create());
        $outcome = $this->runWithFirmContext($firm, fn () => ConsultationOutcome::factory()->forFirm($firm)->create(['name' => 'Retained']));
        $heldAt = now();

        $this->runWithFirmContext($firm, function () use ($consultation, $outcome, $heldAt): void {
            $test = Livewire::test(ConsultationsRelationManager::class, [
                'ownerRecord' => $consultation->firmLead,
                'pageClass' => ViewFirmLead::class,
            ]);
            $test->mountTableAction('markConsultationHeld', $consultation);
            $test->setTableActionData([
                'held_at' => $heldAt->toDateTimeString(),
                'consultation_outcome_id' => $outcome->id,
                'converted' => true,
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Consultation marked as held');
        });

        $freshConsultation = $this->runWithFirmContext($firm, fn () => Consultation::query()->find($consultation->id));
        $this->assertNotNull($freshConsultation->held_at);
        $this->assertSame($outcome->id, $freshConsultation->consultation_outcome_id);
        $this->assertTrue($freshConsultation->converted);

        $freshLead = $this->runWithFirmContext($firm, fn () => FirmLead::query()->find($lead->id));
        $this->assertSame(FirmLeadStatus::ConsultationHeld, $freshLead->status);
    }

    public function test_mark_held_action_is_hidden_once_the_consultation_already_has_a_held_at(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id]));
        $consultation = $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($lead)->held()->create());

        $this->runWithFirmContext($firm, function () use ($lead, $consultation): void {
            $test = Livewire::test(ConsultationsRelationManager::class, [
                'ownerRecord' => $lead,
                'pageClass' => ViewFirmLead::class,
            ]);
            $test->assertTableActionHidden('markConsultationHeld', $consultation);
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
