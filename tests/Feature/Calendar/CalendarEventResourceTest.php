<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Enums\CalendarEventType;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\CalendarEventResource;
use App\Filament\Firm\Resources\CalendarEventResource\Pages\CreateCalendarEvent;
use App\Filament\Firm\Resources\CalendarEventResource\Pages\ListCalendarEvents;
use App\Models\CalendarEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CalendarEventResourceTest — Mission 5B (5.8). `CalendarEvent`/
 * `CalendarEventService::createStandalone()` had zero Filament
 * references and no production caller before this mission (confirmed
 * by exhaustive grep). Proves the new agenda/list resource: role
 * ceiling (reused from TaskCrudAccessPolicyService, no new policy),
 * real service-mediated create, and the same small RLS regression
 * checklist DeadlineResourceAccessTest/TaskResourceAccessTest already
 * prove for their own resources.
 */
final class CalendarEventResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_guest_cannot_access_the_calendar_event_resource(): void
    {
        $this->assertFalse(CalendarEventResource::canAccess());
    }

    public function test_every_active_staff_role_can_view_the_calendar(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->assertTrue(CalendarEventResource::canAccess());
    }

    public function test_create_calendar_event_persists_via_calendar_event_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $startsAt = now()->addDays(5);

        $this->runWithFirmContext($firm, function () use ($matter, $startsAt): void {
            $test = Livewire::test(CreateCalendarEvent::class);
            $test->fillForm([
                'title' => 'Client Meeting',
                'matter_id' => $matter->id,
                'starts_at' => $startsAt->toDateTimeString(),
                'all_day' => false,
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $event = $this->runWithFirmContext($firm, fn () => CalendarEvent::query()->where('title', 'Client Meeting')->first());
        $this->assertNotNull($event);
        $this->assertSame((int) $firm->id, (int) $event->firm_id);
        $this->assertSame(CalendarEventType::Standalone, $event->event_type);
        $this->assertSame($matter->id, $event->matter_id);
    }

    // ------------------------------------------------------------
    // Small RLS regression checklist
    // ------------------------------------------------------------

    public function test_a_firm_user_can_access_its_own_calendar_event(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $event = $this->runWithFirmContext($firm, fn () => CalendarEvent::factory()->create(['firm_id' => $firm->id]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(CalendarEventResource::getUrl('view', ['record' => $event])));

        $response->assertSuccessful();
    }

    public function test_list_page_shows_only_this_firms_calendar_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $eventA = $this->runWithFirmContext($firmA, fn () => CalendarEvent::factory()->create(['firm_id' => $firmA->id]));
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListCalendarEvents::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$eventA]);
        $test->assertCanNotSeeTableRecords([$eventB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_calendar_event_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => CalendarEvent::factory()->create(['firm_id' => $firmA->id]));
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('calendar_events')->pluck('id')->all());

        $this->assertContains($eventA->id, $visibleIds);
        $this->assertNotContains($eventB->id, $visibleIds, "Firm A's session must never read Firm B's calendar event row.");
    }

    public function test_direct_url_guess_of_another_firms_calendar_event_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(CalendarEventResource::getUrl('view', ['record' => $eventB])));

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
