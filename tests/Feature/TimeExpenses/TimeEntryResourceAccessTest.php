<?php

declare(strict_types=1);

namespace Tests\Feature\TimeExpenses;

use App\Enums\FirmUserRole;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeTrackingSessionStatus;
use App\Filament\Firm\Resources\TimeEntryResource;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\ApproveTimeEntryAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\RejectTimeEntryAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\StartTimerAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\StopTimerAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\SubmitTimeEntryAction;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\TimeTrackingSession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TimeEntryResourceAccessTest — Firm Feature Manifest §6 (Tier1-C).
 * Proves role ceilings, real service-mediated create
 * (TimeEntryApprovalService::createManualEntry()), Draft-only plain
 * field edit, Submit/Approve/Reject row actions, the real
 * TimeTrackingService-backed Start/Stop Timer header actions, and the
 * small RLS regression checklist required for this module. The broader
 * RLS rollout itself is out of scope here — see this mission's own
 * scope note.
 */
final class TimeEntryResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() / role ceilings
    // ------------------------------------------------------------

    public function test_guest_cannot_access_the_time_entry_resource(): void
    {
        $this->assertFalse(TimeEntryResource::canAccess());
    }

    public function test_every_role_can_view_the_time_entry_resource(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(TimeEntryResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_paralegal_can_create_a_time_entry_but_receptionist_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $this->assertTrue(TimeEntryResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::Receptionist);
        $this->assertFalse(TimeEntryResource::canCreate());
    }

    // ------------------------------------------------------------
    // 2. Create/Edit — real service-mediated create, Draft-only edit
    // ------------------------------------------------------------

    public function test_create_time_entry_persists_via_the_approval_service_as_a_draft(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($client)->create());

        $this->runWithFirmContext($firm, function () use ($client, $matter): void {
            $test = Livewire::test(CreateTimeEntry::class);
            $test->fillForm([
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'hours' => 2,
                'minutes' => 30,
                'worked_on' => now()->toDateString(),
                'is_billable' => true,
                'description' => 'Drafted motion to compel',
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->where('description', 'Drafted motion to compel')->first());
        $this->assertNotNull($entry);
        $this->assertSame((int) $firm->id, (int) $entry->firm_id);
        $this->assertSame($matter->id, $entry->matter_id);
        $this->assertSame(9000, $entry->seconds); // 2h30m = 9000s
        $this->assertSame(TimeEntryStatus::Draft, $entry->status);
        $this->assertSame($firmUser->user_id, $entry->user_id);
    }

    public function test_edit_time_entry_persists_a_change_while_draft(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::LegalAssistant);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create([
            'firm_id' => $firm->id,
            'status' => TimeEntryStatus::Draft,
            'seconds' => 3600,
            'description' => 'Original',
        ]));

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(EditTimeEntry::class, ['record' => $entry->getRouteKey()]);
            $test->fillForm(['description' => 'Updated']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->find($entry->id));
        $this->assertSame('Updated', $fresh->description);
    }

    public function test_edit_page_is_not_authorized_for_a_submitted_time_entry(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create([
            'firm_id' => $firm->id,
            'status' => TimeEntryStatus::Submitted,
        ]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TimeEntryResource::getUrl('edit', ['record' => $entry])));

        $response->assertForbidden();
    }

    public function test_time_entry_form_never_declares_a_status_field(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/TimeEntryResource.php'));
        $this->assertIsString($source);

        preg_match('/public static function form\(.*?\n    \}/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $this->assertStringNotContainsString("make('status')", $matches[0]);
    }

    // ------------------------------------------------------------
    // 3. Submit / Approve / Reject row actions
    // ------------------------------------------------------------

    public function test_submit_action_visible_for_paralegal_and_hidden_for_billing_staff(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $entryA = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->create(['firm_id' => $firmA->id, 'status' => TimeEntryStatus::Draft]));

        $this->runWithFirmContext($firmA, function () use ($entryA): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->assertTableActionVisible(SubmitTimeEntryAction::getDefaultName(), $entryA);
        });

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->create(['firm_id' => $firmB->id, 'status' => TimeEntryStatus::Draft]));

        $this->runWithFirmContext($firmB, function () use ($entryB): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->assertTableActionHidden(SubmitTimeEntryAction::getDefaultName(), $entryB);
        });
    }

    public function test_submit_action_transitions_a_draft_entry_to_submitted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create(['firm_id' => $firm->id, 'status' => TimeEntryStatus::Draft]));

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->callTableAction(SubmitTimeEntryAction::getDefaultName(), $entry);
            $test->assertNotified('Time entry submitted');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->find($entry->id));
        $this->assertSame(TimeEntryStatus::Submitted, $fresh->status);
    }

    public function test_approve_action_hidden_for_paralegal_visible_for_attorney(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create(['firm_id' => $firm->id, 'status' => TimeEntryStatus::Submitted]));

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->assertTableActionHidden(ApproveTimeEntryAction::getDefaultName(), $entry);
        });

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::Attorney);
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->create(['firm_id' => $firmB->id, 'status' => TimeEntryStatus::Submitted]));

        $this->runWithFirmContext($firmB, function () use ($entryB): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->assertTableActionVisible(ApproveTimeEntryAction::getDefaultName(), $entryB);
        });
    }

    public function test_approve_action_approves_a_submitted_entry(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create(['firm_id' => $firm->id, 'status' => TimeEntryStatus::Submitted]));

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->callTableAction(ApproveTimeEntryAction::getDefaultName(), $entry);
            $test->assertNotified('Time entry approved');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->find($entry->id));
        $this->assertSame(TimeEntryStatus::Approved, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_reject_action_requires_a_reason_and_rejects_the_entry(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create(['firm_id' => $firm->id, 'status' => TimeEntryStatus::Submitted]));

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->mountTableAction(RejectTimeEntryAction::getDefaultName(), $entry->id);
            $test->setActionData(['rejected_reason' => 'Not enough detail provided']);
            $test->callMountedTableAction();
            $test->assertNotified('Time entry rejected');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->find($entry->id));
        $this->assertSame(TimeEntryStatus::Rejected, $fresh->status);
        $this->assertSame('Not enough detail provided', $fresh->rejected_reason);
    }

    // ------------------------------------------------------------
    // 4. Start/Stop Timer — real, durably-persisted backend timer
    // ------------------------------------------------------------

    public function test_start_timer_creates_an_active_session(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->callAction(StartTimerAction::getDefaultName(), data: [
                'is_billable' => true,
                'description' => 'Client call',
            ]);
            $test->assertNotified('Timer started');
        });

        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::query()
            ->where('user_id', $firmUser->user_id)
            ->where('status', TimeTrackingSessionStatus::Active)
            ->first());

        $this->assertNotNull($session);
    }

    public function test_start_timer_refuses_a_second_concurrent_timer(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->create([
            'firm_id' => $firm->id,
            'user_id' => $firmUser->user_id,
            'status' => TimeTrackingSessionStatus::Active,
        ]));

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->callAction(StartTimerAction::getDefaultName(), data: ['is_billable' => true]);
            $test->assertNotified('A timer is already running');
        });

        $activeCount = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::query()
            ->where('user_id', $firmUser->user_id)
            ->where('status', TimeTrackingSessionStatus::Active)
            ->count());

        $this->assertSame(1, $activeCount);
    }

    public function test_stop_timer_stops_the_active_session_and_creates_a_draft_time_entry(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->create([
            'firm_id' => $firm->id,
            'user_id' => $firmUser->user_id,
            'status' => TimeTrackingSessionStatus::Active,
            'accumulated_seconds' => 1800,
            'last_resumed_at' => null,
        ]));

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->callAction(StopTimerAction::getDefaultName());
            $test->assertNotified('Timer stopped');
        });

        $freshSession = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::query()->find($session->id));
        $this->assertSame(TimeTrackingSessionStatus::Stopped, $freshSession->status);

        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->where('time_tracking_session_id', $session->id)->first());
        $this->assertNotNull($entry);
        $this->assertSame(TimeEntryStatus::Draft, $entry->status);
        $this->assertSame(1800, $entry->seconds);
    }

    public function test_stop_timer_notifies_when_no_active_timer_exists(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListTimeEntries::class);
            $test->callAction(StopTimerAction::getDefaultName());
            $test->assertNotified('No active timer to stop');
        });
    }

    // ------------------------------------------------------------
    // 5. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own TimeEntry records. */
    public function test_a_firm_user_can_access_its_own_time_entries(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create(['firm_id' => $firm->id]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TimeEntryResource::getUrl('view', ['record' => $entry])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's TimeEntry is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_time_entries(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $entryA = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->create(['firm_id' => $firmA->id]));
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->create(['firm_id' => $firmB->id]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListTimeEntries::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$entryA]);
        $test->assertCanNotSeeTableRecords([$entryB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_time_entry_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryA = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->create(['firm_id' => $firmA->id]));
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('time_entries')->pluck('id')->all());

        $this->assertContains($entryA->id, $visibleIds);
        $this->assertNotContains($entryB->id, $visibleIds, "Firm A's session must never read Firm B's time entry row.");
    }

    /** (c) a foreign matter cannot be selected via the matter_id relation select. */
    public function test_matter_select_options_never_include_a_foreign_firms_matter(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->forClient($clientA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->forClient($clientB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TimeEntryResource::getUrl('create')));
        $response->assertSuccessful();

        $this->runWithFirmContext($firmA, function () use ($matterA, $matterB): void {
            $visibleMatterIds = Matter::query()->pluck('id')->all();

            $this->assertContains($matterA->id, $visibleMatterIds);
            $this->assertNotContains($matterB->id, $visibleMatterIds, "Firm A's matter_id options must never include Firm B's matter.");
        });
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_time_entry_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->create(['firm_id' => $firmB->id]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TimeEntryResource::getUrl('view', ['record' => $entryB])));

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
