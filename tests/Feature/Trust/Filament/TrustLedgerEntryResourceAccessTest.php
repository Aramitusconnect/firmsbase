<?php

declare(strict_types=1);

namespace Tests\Feature\Trust\Filament;

use App\Enums\FirmUserRole;
use App\Enums\TrustChargebackStatus;
use App\Filament\Firm\Resources\TrustLedgerEntryResource;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ReportChargebackAction;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ResolveChargebackAction;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ReverseChargebackAction;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Pages\ListTrustLedgerEntries;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Pages\ViewTrustLedgerEntry;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedgerEntry;
use App\Models\User;
use App\Services\TrustAccountService;
use App\Services\TrustChargebackService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustRefundRequestService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * TrustLedgerEntryResourceAccessTest — List/View ONLY (no Create/Edit
 * page exists at all — proven below by asserting the resource declares
 * no 'create'/'edit' route). Chargeback report -> reverse -> resolve is
 * proven via resulting TrustChargebackEvent state. Also proves the
 * app-layer tenant guard (TrustLedgerEntryResource::getEloquentQuery())
 * still holds for this specific table, which deliberately does NOT use
 * BelongsToTenant (see that Resource's own docblock) — this is the
 * "verify the app-layer guard, don't assume the global scope protects
 * it" checklist item called out for trust_ledger_entries specifically.
 */
final class TrustLedgerEntryResourceAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function makeDepositedEntry(): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account'));
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $ledger = $this->runWithFirmContext($firm, fn () => app(TrustLedgerService::class)->open($firm, $account, $client));

        $entry = $this->runWithFirmContext($firm, function () use ($firm, $ledger) {
            $requester = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
            $approver = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
            $service = app(TrustDepositService::class);
            $requested = $service->requestDeposit($firm, $ledger, $requester, 30000);
            $approved = $service->approveDeposit($firm, $requested, $approver);

            return $service->post($firm, $ledger, $approved);
        });

        return [$firm, $ledger, $entry];
    }

    public function test_the_resource_declares_no_create_or_edit_page(): void
    {
        $pages = TrustLedgerEntryResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_full_chargeback_lifecycle_via_filament(): void
    {
        [$firm, , $entry] = $this->makeDepositedEntry();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ViewTrustLedgerEntry::class, ['record' => $entry->getRouteKey()]);
            $test->mountAction(ReportChargebackAction::getDefaultName());
            $test->setActionData(['amount' => 300, 'reason' => 'Bank reported a chargeback']);
            $test->callMountedAction();
            $test->assertNotified('Chargeback reported');
        });

        $chargeback = $this->runWithFirmContext($firm, fn () => TrustChargebackEvent::query()->where('original_trust_ledger_entry_id', $entry->id)->first());
        $this->assertSame(TrustChargebackStatus::Reported, $chargeback->status);

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ViewTrustLedgerEntry::class, ['record' => $entry->getRouteKey()]);
            $test->callAction(ReverseChargebackAction::getDefaultName());
            $test->assertNotified('Chargeback reversed');
        });

        $reversed = $this->runWithFirmContext($firm, fn () => TrustChargebackEvent::query()->find($chargeback->id));
        $this->assertSame(TrustChargebackStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_trust_ledger_entry_id);

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ViewTrustLedgerEntry::class, ['record' => $entry->getRouteKey()]);
            $test->callAction(ResolveChargebackAction::getDefaultName());
            $test->assertNotified('Chargeback resolved');
        });

        $resolved = $this->runWithFirmContext($firm, fn () => TrustChargebackEvent::query()->find($chargeback->id));
        $this->assertSame(TrustChargebackStatus::Resolved, $resolved->status);

        // The original entry itself was never mutated (append-only, rule #1).
        $original = $this->runWithFirmContext($firm, fn () => TrustLedgerEntry::query()->find($entry->id));
        $this->assertSame(30000, $original->amount_cents);
        $this->assertNull($original->reverses_entry_id);
    }

    public function test_report_chargeback_is_hidden_for_a_non_deposit_entry(): void
    {
        [$firm, $ledger] = $this->makeDepositedEntry();

        $refundEntry = $this->runWithFirmContext($firm, function () use ($firm, $ledger) {
            $requester = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
            $approver = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
            $service = app(TrustRefundRequestService::class);
            $request = $service->requestRefund($firm, $ledger, $requester, 5000);
            $service->approveRefund($firm, $request, $approver);

            return $service->complete($firm, $request, $approver);
        });

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($refundEntry): void {
            $test = Livewire::test(ViewTrustLedgerEntry::class, ['record' => $refundEntry->getRouteKey()]);
            $test->assertActionHidden(ReportChargebackAction::getDefaultName());
        });
    }

    public function test_reverse_chargeback_is_hidden_without_a_reported_chargeback(): void
    {
        [$firm, , $entry] = $this->makeDepositedEntry();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ViewTrustLedgerEntry::class, ['record' => $entry->getRouteKey()]);
            $test->assertActionHidden(ReverseChargebackAction::getDefaultName());
            $test->assertActionHidden(ResolveChargebackAction::getDefaultName());
        });
    }

    public function test_billing_staff_cannot_reverse_a_chargeback(): void
    {
        [$firm, , $entry] = $this->makeDepositedEntry();
        $this->runWithFirmContext($firm, function () use ($firm, $entry): void {
            $reporter = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::BillingStaff)->create();
            app(TrustChargebackService::class)->report($firm, $entry, $reporter, $entry->amount_cents, 'Reported');
        });

        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ViewTrustLedgerEntry::class, ['record' => $entry->getRouteKey()]);
            $test->assertActionHidden(ReverseChargebackAction::getDefaultName());
        });
    }

    // ------------------------------------------------------------
    // App-layer tenant guard for trust_ledger_entries (NOT
    // BelongsToTenant — see TrustLedgerEntryResource::getEloquentQuery())
    // ------------------------------------------------------------

    public function test_list_page_shows_only_this_firms_ledger_entries_despite_no_belongs_to_tenant_scope(): void
    {
        [$firmA, , $entryA] = $this->makeDepositedEntry();
        [, , $entryB] = $this->makeDepositedEntry();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListTrustLedgerEntries::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$entryA]);
        $test->assertCanNotSeeTableRecords([$entryB]);
    }

    public function test_direct_url_guess_of_another_firms_ledger_entry_never_succeeds(): void
    {
        [$firmA] = $this->makeDepositedEntry();
        [, , $entryB] = $this->makeDepositedEntry();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TrustLedgerEntryResource::getUrl('view', ['record' => $entryB])));

        $response->assertNotFound();
    }

    /**
     * Proves the app-layer guard by INSPECTING the query builder rather
     * than executing it: trust_ledger_entries has permanent FORCE ROW
     * LEVEL SECURITY, so a query issued with no database session
     * `app.current_firm_id` set (which is exactly the state immediately
     * after this method call, since it is deliberately NOT wrapped in
     * runWithFirmContext()) would return an empty result set from
     * Postgres's OWN fail-closed enforcement regardless of whether this
     * Resource's PHP-level filter is present or correct — executing the
     * query here would prove RLS works (already covered by
     * TrustLedgerEntriesForceRlsActivationTest), not that THIS
     * Resource's own `getEloquentQuery()` override does its job. This
     * test instead asserts the override actually attaches an explicit
     * `trust_ledger_entries.firm_id = <acting firm id>` constraint to
     * the builder — the PHP-layer half of this table's tenant guard,
     * independent of and in addition to whatever the database session
     * happens to have set at the time a given query runs (e.g. during a
     * Livewire AJAX interaction that does not carry this app's
     * EstablishFirmTenantContext/ApplyTenantDatabaseContext middleware —
     * see ScopesQueriesToActiveFirm's own docblock).
     */
    public function test_the_eloquent_query_override_attaches_an_explicit_firm_id_constraint(): void
    {
        [$firmA] = $this->makeDepositedEntry();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $query = TrustLedgerEntryResource::getEloquentQuery();

        $matchingWheres = collect($query->getQuery()->wheres)->filter(
            fn (array $where): bool => ($where['column'] ?? null) === 'trust_ledger_entries.firm_id'
                && ($where['operator'] ?? null) === '='
                && ($where['value'] ?? null) === $firmA->id,
        );

        $this->assertTrue(
            $matchingWheres->isNotEmpty(),
            'Expected TrustLedgerEntryResource::getEloquentQuery() to constrain trust_ledger_entries.firm_id to the acting FirmUser\'s own firm_id.',
        );
    }

    public function test_the_eloquent_query_override_scopes_to_a_nonexistent_firm_when_no_firm_user_is_authenticated(): void
    {
        $query = TrustLedgerEntryResource::getEloquentQuery();

        $matchingWheres = collect($query->getQuery()->wheres)->filter(
            fn (array $where): bool => ($where['column'] ?? null) === 'trust_ledger_entries.firm_id'
                && ($where['operator'] ?? null) === '='
                && ($where['value'] ?? null) === 0,
        );

        $this->assertTrue(
            $matchingWheres->isNotEmpty(),
            'Expected an unauthenticated/no-active-firm-user query to be constrained to a firm_id of 0 (matches nothing) rather than left unfiltered.',
        );
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
