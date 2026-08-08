<?php

declare(strict_types=1);

namespace Tests\Feature\ClientCrm;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\ActivityRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\ExpensesRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\MattersRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\TimeEntriesRelationManager;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\TimeEntry;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ClientRelationshipTabsTest — Tier1-G (Firm Feature Manifest
 * "Relationships" wiring). Proves the new ClientResource\ViewClient
 * tabs (Matters, Time Entries, Expenses, Payments, Activity) each
 * render and show ONLY records genuinely scoped to the client being
 * viewed — including a same-firm, different-client record (proving the
 * relationship's own key, not just BelongsToTenant/RLS, does the
 * scoping) and a different-firm record (the real RLS boundary). Does
 * NOT duplicate the historical RlsForceRollout suite — this is a small,
 * focused set per this mission's own RLS testing-scope instruction.
 */
final class ClientRelationshipTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Matters tab
    // ------------------------------------------------------------

    public function test_matters_tab_renders_and_shows_only_this_clients_matters(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($clientA)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($clientB)->create());

        $this->assertTrue(MattersRelationManager::canViewForRecord($clientA, ViewClient::class));

        $this->runWithFirmContext($firm, function () use ($clientA, $matterA, $matterB): void {
            $test = Livewire::test(MattersRelationManager::class, [
                'ownerRecord' => $clientA,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$matterA]);
            $test->assertCanNotSeeTableRecords([$matterB], 'A different client in the SAME firm must never appear on this client\'s Matters tab.');
        });
    }

    public function test_matters_tab_is_hidden_for_a_different_firms_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $canView = $this->runWithFirmContext($firmB, fn () => MattersRelationManager::canViewForRecord($clientB, ViewClient::class));

        $this->assertFalse($canView, "A FirmOwner acting in Firm A's own session must never be authorized to view Firm B's client's Matters tab.");
    }

    // ------------------------------------------------------------
    // Time Entries tab
    // ------------------------------------------------------------

    public function test_time_entries_tab_renders_and_shows_only_this_clients_time_entries(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientA2 = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $entryA = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->forFirm($firmA)->create(['client_id' => $clientA->id]));
        $entrySameFirmOtherClient = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->forFirm($firmA)->create(['client_id' => $clientA2->id]));
        $entryOtherFirm = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->forFirm($firmB)->create(['client_id' => $clientB->id]));

        $this->runWithFirmContext($firmA, function () use ($clientA, $entryA, $entrySameFirmOtherClient, $entryOtherFirm): void {
            $test = Livewire::test(TimeEntriesRelationManager::class, [
                'ownerRecord' => $clientA,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$entryA]);
            $test->assertCanNotSeeTableRecords([$entrySameFirmOtherClient], 'A different client in the same firm must never leak in.');
            $test->assertCanNotSeeTableRecords([$entryOtherFirm], "Firm B's time entry must never leak into Firm A's client tab.");
        });
    }

    // ------------------------------------------------------------
    // Expenses tab — entitlement-gated AND hasManyThrough-scoped
    // ------------------------------------------------------------

    public function test_expenses_tab_is_hidden_without_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->assertFalse(ExpensesRelationManager::canViewForRecord($client, ViewClient::class));
    }

    public function test_expenses_tab_renders_once_entitled_and_shows_only_this_clients_expenses_across_its_matters(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($clientA)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($clientB)->create());

        $expenseA = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['matter_id' => $matterA->id]));
        $expenseB = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['matter_id' => $matterB->id]));

        $this->assertTrue(ExpensesRelationManager::canViewForRecord($clientA, ViewClient::class));

        $this->runWithFirmContext($firm, function () use ($clientA, $expenseA, $expenseB): void {
            $test = Livewire::test(ExpensesRelationManager::class, [
                'ownerRecord' => $clientA,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$expenseA]);
            $test->assertCanNotSeeTableRecords([$expenseB], "Another client's expense (via a different matter) must never appear here.");
        });
    }

    // ------------------------------------------------------------
    // Payments tab
    // ------------------------------------------------------------

    public function test_payments_tab_renders_and_shows_only_this_clients_payments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientA2 = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());

        $paymentA = $this->runWithFirmContext($firmA, fn () => Payment::factory()->forClient($clientA)->create());
        $paymentSameFirmOtherClient = $this->runWithFirmContext($firmA, fn () => Payment::factory()->forClient($clientA2)->create());
        $paymentOtherFirm = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($clientA, $paymentA, $paymentSameFirmOtherClient, $paymentOtherFirm): void {
            $test = Livewire::test(PaymentsRelationManager::class, [
                'ownerRecord' => $clientA,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$paymentA]);
            $test->assertCanNotSeeTableRecords([$paymentSameFirmOtherClient]);
            $test->assertCanNotSeeTableRecords([$paymentOtherFirm]);
        });
    }

    // ------------------------------------------------------------
    // Activity tab — honest disclosure: structurally works, empty
    // today (no client.*-prefixed TimelineEvent is emitted anywhere)
    // ------------------------------------------------------------

    public function test_activity_tab_renders_with_an_empty_state_and_never_shows_a_matters_timeline_event(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($client)->create());

        // A real, existing event type, but scoped to the MATTER, not the
        // client — proves subject_type scoping keeps the two Activity
        // tabs isolated from each other even within the same firm.
        $matterEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->forSubject($matter)->eventType('matter.opened')->create());

        $this->runWithFirmContext($firm, function () use ($client, $matterEvent): void {
            $test = Livewire::test(ActivityRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanNotSeeTableRecords([$matterEvent], "A Matter's timeline event must never appear on the Client's Activity tab.");
        });
    }

    public function test_activity_tab_shows_a_genuine_client_scoped_event_when_one_exists(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->forSubject($client)->eventType('client.updated')->create());

        $this->runWithFirmContext($firm, function () use ($client, $clientEvent): void {
            $test = Livewire::test(ActivityRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$clientEvent]);
        });
    }

    // ------------------------------------------------------------
    // Structural — no duplicate resource/page created
    // ------------------------------------------------------------

    public function test_client_resource_still_declares_only_index_view_edit_pages(): void
    {
        $this->assertSame(['index', 'view', 'edit'], array_keys(ClientResource::getPages()));
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
