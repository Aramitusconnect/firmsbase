<?php

declare(strict_types=1);

namespace Tests\Feature\TasksDeadlines;

use App\Enums\CalendarEventType;
use App\Enums\DeadlineStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\DeadlineResource;
use App\Filament\Firm\Resources\DeadlineResource\Actions\CompleteDeadlineAction;
use App\Filament\Firm\Resources\DeadlineResource\Pages\CreateDeadline;
use App\Filament\Firm\Resources\DeadlineResource\Pages\EditDeadline;
use App\Filament\Firm\Resources\DeadlineResource\Pages\ListDeadlines;
use App\Models\CalendarEvent;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DeadlineResourceAccessTest — Firm Feature Manifest §3 (Tier1-B).
 * Proves role ceilings (narrower than Task — Receptionist excluded),
 * real service-mediated create (DeadlineService::create() — and that it
 * really does create the linked CalendarEvent, in the same request),
 * the narrow safe-field-only edit form, the CompleteDeadlineAction, and
 * the same small RLS regression checklist TaskResourceAccessTest
 * proves for Task.
 */
final class DeadlineResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() / role ceilings — narrower than Task
    // ------------------------------------------------------------

    public function test_guest_cannot_access_the_deadline_resource(): void
    {
        $this->assertFalse(DeadlineResource::canAccess());
    }

    public function test_paralegal_can_create_a_deadline_but_receptionist_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $this->assertTrue(DeadlineResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::Receptionist);
        $this->assertFalse(DeadlineResource::canCreate());
    }

    public function test_billing_staff_can_view_but_not_create_a_deadline(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->assertTrue(DeadlineResource::canAccess());
        $this->assertFalse(DeadlineResource::canCreate());
    }

    // ------------------------------------------------------------
    // 2. Create — DeadlineService::create() really creates the linked
    //    CalendarEvent
    // ------------------------------------------------------------

    public function test_create_deadline_persists_via_deadline_service_and_creates_a_linked_calendar_event(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $dueAt = now()->addDays(14);

        $this->runWithFirmContext($firm, function () use ($dueAt): void {
            $test = Livewire::test(CreateDeadline::class);
            $test->fillForm([
                'title' => 'File Response Brief',
                'deadline_type' => 'filing_deadline',
                'due_at' => $dueAt->toDateTimeString(),
                'jurisdiction' => 'CA Superior Court',
                'source' => 'court_order',
                'reminder_offsets_days' => ['7', '3', '1'],
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $deadline = $this->runWithFirmContext($firm, fn () => Deadline::query()->where('title', 'File Response Brief')->first());
        $this->assertNotNull($deadline);
        $this->assertSame((int) $firm->id, (int) $deadline->firm_id);
        $this->assertSame(DeadlineStatus::Upcoming, $deadline->status);
        $this->assertSame([7, 3, 1], $deadline->reminder_offsets_days);

        $calendarEvent = $this->runWithFirmContext($firm, fn () => CalendarEvent::query()
            ->where('subject_type', Deadline::class)
            ->where('subject_id', $deadline->id)
            ->first());

        $this->assertNotNull($calendarEvent, 'DeadlineService::create() must create a linked CalendarEvent in the same transaction.');
        $this->assertSame(CalendarEventType::Deadline, $calendarEvent->event_type);
        $this->assertSame('File Response Brief', $calendarEvent->title);
    }

    public function test_deadline_form_never_declares_a_status_field(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/DeadlineResource.php'));
        $this->assertIsString($source);

        preg_match('/public static function form\(.*?\n    \}/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $this->assertStringNotContainsString("make('status')", $matches[0]);
    }

    // ------------------------------------------------------------
    // 3. Edit — narrow safe-field-only form
    // ------------------------------------------------------------

    public function test_edit_deadline_persists_a_safe_field_change_but_form_excludes_due_at(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $originalDueAt = now()->addDays(10);
        $deadline = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create([
            'firm_id' => $firm->id,
            'title' => 'Original Title',
            'due_at' => $originalDueAt,
        ]));

        $this->runWithFirmContext($firm, function () use ($deadline): void {
            $test = Livewire::test(EditDeadline::class, ['record' => $deadline->getRouteKey()]);
            $test->assertFormFieldDoesNotExist('due_at');
            $test->assertFormFieldDoesNotExist('matter_id');
            $test->assertFormFieldDoesNotExist('deadline_type');
            $test->fillForm(['title' => 'Updated Title']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Deadline::query()->find($deadline->id));
        $this->assertSame('Updated Title', $fresh->title);
        $this->assertEquals($originalDueAt->toDateTimeString(), $fresh->due_at->toDateTimeString());
    }

    // ------------------------------------------------------------
    // 4. CompleteDeadlineAction
    // ------------------------------------------------------------

    public function test_complete_deadline_action_visible_for_attorney_and_hidden_for_billing_staff(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Attorney);
        $deadlineA = $this->runWithFirmContext($firmA, fn () => Deadline::factory()->create(['firm_id' => $firmA->id]));

        $this->runWithFirmContext($firmA, function () use ($deadlineA): void {
            $test = Livewire::test(ListDeadlines::class);
            $test->assertTableActionVisible(CompleteDeadlineAction::getDefaultName(), $deadlineA);
        });

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $deadlineB = $this->runWithFirmContext($firmB, fn () => Deadline::factory()->create(['firm_id' => $firmB->id]));

        $this->runWithFirmContext($firmB, function () use ($deadlineB): void {
            $test = Livewire::test(ListDeadlines::class);
            $test->assertTableActionHidden(CompleteDeadlineAction::getDefaultName(), $deadlineB);
        });
    }

    public function test_complete_deadline_action_completes_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $deadline = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, function () use ($deadline): void {
            $test = Livewire::test(ListDeadlines::class);
            $test->callTableAction(CompleteDeadlineAction::getDefaultName(), $deadline);
            $test->assertNotified('Deadline completed');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Deadline::query()->find($deadline->id));
        $this->assertSame(DeadlineStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    // ------------------------------------------------------------
    // 5. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own Deadline records. */
    public function test_a_firm_user_can_access_its_own_deadlines(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $deadline = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(DeadlineResource::getUrl('view', ['record' => $deadline])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's Deadline is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_deadlines(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $deadlineA = $this->runWithFirmContext($firmA, fn () => Deadline::factory()->create(['firm_id' => $firmA->id]));
        $deadlineB = $this->runWithFirmContext($firmB, fn () => Deadline::factory()->create(['firm_id' => $firmB->id]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListDeadlines::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$deadlineA]);
        $test->assertCanNotSeeTableRecords([$deadlineB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_deadline_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deadlineA = $this->runWithFirmContext($firmA, fn () => Deadline::factory()->create(['firm_id' => $firmA->id]));
        $deadlineB = $this->runWithFirmContext($firmB, fn () => Deadline::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('deadlines')->pluck('id')->all());

        $this->assertContains($deadlineA->id, $visibleIds);
        $this->assertNotContains($deadlineB->id, $visibleIds, "Firm A's session must never read Firm B's deadline row.");
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_deadline_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $deadlineB = $this->runWithFirmContext($firmB, fn () => Deadline::factory()->create(['firm_id' => $firmB->id]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(DeadlineResource::getUrl('view', ['record' => $deadlineB])));

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
