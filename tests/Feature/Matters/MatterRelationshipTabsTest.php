<?php

declare(strict_types=1);

namespace Tests\Feature\Matters;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\ContactsRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\DeadlinesRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\ExpensesRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\TasksRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\TimeEntriesRelationManager;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Deadline;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MatterRelationshipTabsTest — Tier1-G (Firm Feature Manifest
 * "Relationships" wiring). Proves the new ViewMatter tabs (Contacts,
 * Tasks, Deadlines, Time Entries, Expenses, Payments) each render and
 * show ONLY records genuinely scoped to the matter being viewed —
 * including a same-firm, different-matter/different-client record
 * (proving the relationship's own key does the scoping, not just
 * BelongsToTenant/RLS) and a different-firm record (the real RLS
 * boundary) — plus the Client info-panel link. Small, focused set per
 * this mission's own RLS testing-scope instruction; does not duplicate
 * the historical RlsForceRollout suite or MatterResourceAccessTest's
 * own exhaustive MatterAccessPolicyService coverage.
 */
final class MatterRelationshipTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Contacts tab — shares the matter's own client_id, not a
    // matter_id column on Contact
    // ------------------------------------------------------------

    public function test_contacts_tab_renders_and_shows_only_contacts_of_this_matters_client(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($clientA)->create());

        $contactA = $this->runWithFirmContext($firm, fn () => Contact::factory()->forFirm($firm)->create(['client_id' => $clientA->id]));
        $contactB = $this->runWithFirmContext($firm, fn () => Contact::factory()->forFirm($firm)->create(['client_id' => $clientB->id]));

        $this->assertTrue($this->runWithFirmContext($firm, fn () => ContactsRelationManager::canViewForRecord($matter, ViewMatter::class)));

        $this->runWithFirmContext($firm, function () use ($matter, $contactA, $contactB): void {
            $test = Livewire::test(ContactsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$contactA]);
            $test->assertCanNotSeeTableRecords([$contactB], "A different client's contact (same firm) must never appear on this matter's Contacts tab.");
        });
    }

    public function test_contacts_tab_is_hidden_for_an_unassigned_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $canView = $this->runWithFirmContext($firm, fn () => ContactsRelationManager::canViewForRecord($matter, ViewMatter::class));

        $this->assertFalse($canView);
    }

    // ------------------------------------------------------------
    // Tasks tab
    // ------------------------------------------------------------

    public function test_tasks_tab_renders_and_shows_only_this_matters_tasks(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterA2 = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $taskA = $this->runWithFirmContext($firmA, fn () => Task::factory()->create(['firm_id' => $firmA->id, 'matter_id' => $matterA->id]));
        $taskSameFirmOtherMatter = $this->runWithFirmContext($firmA, fn () => Task::factory()->create(['firm_id' => $firmA->id, 'matter_id' => $matterA2->id]));
        $taskOtherFirm = $this->runWithFirmContext($firmB, fn () => Task::factory()->create(['firm_id' => $firmB->id, 'matter_id' => $matterB->id]));

        $this->runWithFirmContext($firmA, function () use ($matterA, $taskA, $taskSameFirmOtherMatter, $taskOtherFirm): void {
            $test = Livewire::test(TasksRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$taskA]);
            $test->assertCanNotSeeTableRecords([$taskSameFirmOtherMatter]);
            $test->assertCanNotSeeTableRecords([$taskOtherFirm]);
        });
    }

    // ------------------------------------------------------------
    // Deadlines tab
    // ------------------------------------------------------------

    public function test_deadlines_tab_renders_and_shows_only_this_matters_deadlines(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $deadlineA = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matterA->id]));
        $deadlineB = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matterB->id]));

        $this->runWithFirmContext($firm, function () use ($matterA, $deadlineA, $deadlineB): void {
            $test = Livewire::test(DeadlinesRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$deadlineA]);
            $test->assertCanNotSeeTableRecords([$deadlineB]);
        });
    }

    // ------------------------------------------------------------
    // Time Entries tab
    // ------------------------------------------------------------

    public function test_time_entries_tab_renders_and_shows_only_this_matters_time_entries(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $entryA = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create(['matter_id' => $matterA->id]));
        $entryB = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create(['matter_id' => $matterB->id]));

        $this->runWithFirmContext($firm, function () use ($matterA, $entryA, $entryB): void {
            $test = Livewire::test(TimeEntriesRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$entryA]);
            $test->assertCanNotSeeTableRecords([$entryB]);
        });
    }

    // ------------------------------------------------------------
    // Expenses tab — entitlement-gated
    // ------------------------------------------------------------

    public function test_expenses_tab_is_hidden_without_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertFalse(ExpensesRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    public function test_expenses_tab_renders_once_entitled_and_shows_only_this_matters_expenses(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $expenseA = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['matter_id' => $matterA->id]));
        $expenseB = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['matter_id' => $matterB->id]));

        $this->runWithFirmContext($firm, function () use ($matterA, $expenseA, $expenseB): void {
            $test = Livewire::test(ExpensesRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$expenseA]);
            $test->assertCanNotSeeTableRecords([$expenseB]);
        });
    }

    // ------------------------------------------------------------
    // Payments tab
    // ------------------------------------------------------------

    public function test_payments_tab_renders_and_shows_only_this_matters_payments(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $paymentA = $this->runWithFirmContext($firm, fn () => Payment::factory()->forMatter($matterA)->create());
        $paymentB = $this->runWithFirmContext($firm, fn () => Payment::factory()->forMatter($matterB)->create());

        $this->runWithFirmContext($firm, function () use ($matterA, $paymentA, $paymentB): void {
            $test = Livewire::test(PaymentsRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$paymentA]);
            $test->assertCanNotSeeTableRecords([$paymentB]);
        });
    }

    public function test_payments_tab_is_hidden_for_an_unassigned_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $canView = $this->runWithFirmContext($firm, fn () => PaymentsRelationManager::canViewForRecord($matter, ViewMatter::class));

        $this->assertFalse($canView, 'An unassigned Paralegal must never be authorized to view a matter\'s Payments tab.');
    }

    // ------------------------------------------------------------
    // Client info panel — link out, not a relation manager
    // ------------------------------------------------------------

    public function test_view_matter_client_panel_links_to_the_full_client_resource_page(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($client)->create());

        $expectedUrl = $this->runWithFirmContext($firm, fn () => ClientResource::getUrl('view', ['record' => $client]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertSuccessful();
        $response->assertSee($expectedUrl, false);
    }

    // ------------------------------------------------------------
    // Structural — no duplicate resource/page created
    // ------------------------------------------------------------

    public function test_matter_resource_still_declares_only_index_and_view_pages(): void
    {
        $this->assertSame(['index', 'view'], array_keys(MatterResource::getPages()));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function expenseEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        return $firm;
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
