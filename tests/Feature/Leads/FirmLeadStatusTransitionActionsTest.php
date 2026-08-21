<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\FirmLeadStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\MarkLeadContactedAction;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\MarkLeadLostAction;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\ScheduleConsultationAction;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\ListFirmLeads;
use App\Models\Consultation;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmLeadStatusTransitionActionsTest — Mission 5B (5.6/5.7). Proves
 * the three new internal-lifecycle Actions (MarkLeadContactedAction/
 * ScheduleConsultationAction/MarkLeadLostAction) are the first real
 * writers of FirmLeadStatus::Contacted/ConsultationScheduled/Lost
 * (previously only New/Converted were ever written — confirmed by
 * this mission's own exhaustive grep), each gated on
 * ClientCrmAccessPolicyService::canManageLead() exactly like
 * FirmLeadResource's own Create/Edit form, and that
 * ScheduleConsultationAction really creates the Consultation row it
 * claims to.
 */
final class FirmLeadStatusTransitionActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // MarkLeadContactedAction
    // ------------------------------------------------------------

    public function test_mark_contacted_visible_for_paralegal_on_a_new_lead_but_hidden_for_billing_staff(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->create(['firm_id' => $firmA->id, 'status' => FirmLeadStatus::New]));

        $this->runWithFirmContext($firmA, function () use ($leadA): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionVisible(MarkLeadContactedAction::getDefaultName(), $leadA);
        });

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->create(['firm_id' => $firmB->id, 'status' => FirmLeadStatus::New]));

        $this->runWithFirmContext($firmB, function () use ($leadB): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionHidden(MarkLeadContactedAction::getDefaultName(), $leadB);
        });
    }

    public function test_mark_contacted_is_hidden_once_the_lead_is_already_contacted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::Contacted]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionHidden(MarkLeadContactedAction::getDefaultName(), $lead);
        });
    }

    public function test_mark_contacted_action_transitions_status_via_a_plain_update(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::New]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->callTableAction(MarkLeadContactedAction::getDefaultName(), $lead);
            $test->assertNotified('Lead marked as Contacted');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLead::query()->find($lead->id));
        $this->assertSame(FirmLeadStatus::Contacted, $fresh->status);
    }

    // ------------------------------------------------------------
    // ScheduleConsultationAction
    // ------------------------------------------------------------

    public function test_schedule_consultation_action_creates_a_consultation_and_advances_status(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::Contacted]));
        $scheduledAt = now()->addDays(3);

        $this->runWithFirmContext($firm, function () use ($lead, $scheduledAt): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->mountTableAction(ScheduleConsultationAction::getDefaultName(), $lead);
            $test->setTableActionData([
                'scheduled_at' => $scheduledAt->toDateTimeString(),
                'notes' => 'Initial intake call',
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Consultation scheduled');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLead::query()->find($lead->id));
        $this->assertSame(FirmLeadStatus::ConsultationScheduled, $fresh->status);

        $consultation = $this->runWithFirmContext($firm, fn () => Consultation::query()->where('firm_lead_id', $lead->id)->first());
        $this->assertNotNull($consultation);
        $this->assertSame('Initial intake call', $consultation->notes);
        $this->assertEquals($scheduledAt->toDateTimeString(), $consultation->scheduled_at->toDateTimeString());
    }

    public function test_schedule_consultation_action_is_hidden_once_already_scheduled(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::ConsultationScheduled]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionHidden(ScheduleConsultationAction::getDefaultName(), $lead);
        });
    }

    // ------------------------------------------------------------
    // MarkLeadLostAction
    // ------------------------------------------------------------

    public function test_mark_lost_action_available_from_new_contacted_and_consultation_states(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        foreach ([FirmLeadStatus::New, FirmLeadStatus::Contacted, FirmLeadStatus::ConsultationScheduled, FirmLeadStatus::ConsultationHeld] as $status) {
            $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => $status]));

            $this->runWithFirmContext($firm, function () use ($lead): void {
                $test = Livewire::test(ListFirmLeads::class);
                $test->assertTableActionVisible(MarkLeadLostAction::getDefaultName(), $lead);
            });
        }
    }

    public function test_mark_lost_action_is_hidden_for_a_converted_lead(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create([
            'firm_id' => $firm->id,
            'status' => FirmLeadStatus::Converted,
        ]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionHidden(MarkLeadLostAction::getDefaultName(), $lead);
        });
    }

    public function test_mark_lost_action_transitions_status_via_a_plain_update(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->create(['firm_id' => $firm->id, 'status' => FirmLeadStatus::Contacted]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->callTableAction(MarkLeadLostAction::getDefaultName(), $lead);
            $test->assertNotified('Lead marked as Lost');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLead::query()->find($lead->id));
        $this->assertSame(FirmLeadStatus::Lost, $fresh->status);
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
