<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Filament;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\AccountingJournalEntryResource;
use App\Filament\Firm\Resources\AccountingJournalEntryResource\Pages\ListAccountingJournalEntries;
use App\Filament\Firm\Resources\AccountingJournalEntryResource\Pages\ViewAccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\AccountingJournalPostingService;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AccountingJournalEntryResourceAccessTest — mirrors
 * TrustLedgerEntryResourceAccessTest's own shape: proves this Resource
 * declares no Create/Edit page, proves the app-layer tenant guard
 * (accounting_journal_entries deliberately has no BelongsToTenant
 * scope, matching trust_ledger_entries) independent of FORCE RLS, and
 * proves a direct URL guess of another firm's entry never succeeds.
 */
final class AccountingJournalEntryResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function makeFirmWithPostedEntry(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $entry = $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Test payment', now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 10000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 10000],
            ],
        ));

        return [$firm, $entry];
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

    public function test_the_resource_declares_no_create_or_edit_page(): void
    {
        $pages = AccountingJournalEntryResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_list_page_shows_only_this_firms_journal_entries(): void
    {
        [$firmA, $entryA] = $this->makeFirmWithPostedEntry();
        [, $entryB] = $this->makeFirmWithPostedEntry();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListAccountingJournalEntries::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$entryA]);
        $test->assertCanNotSeeTableRecords([$entryB]);
    }

    public function test_direct_url_guess_of_another_firms_entry_never_succeeds(): void
    {
        [$firmA] = $this->makeFirmWithPostedEntry();
        [, $entryB] = $this->makeFirmWithPostedEntry();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(AccountingJournalEntryResource::getUrl('view', ['record' => $entryB])));

        $response->assertNotFound();
    }

    public function test_the_eloquent_query_override_attaches_an_explicit_firm_id_constraint(): void
    {
        [$firmA] = $this->makeFirmWithPostedEntry();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $query = AccountingJournalEntryResource::getEloquentQuery();

        $matchingWheres = collect($query->getQuery()->wheres)->filter(
            fn (array $where): bool => ($where['column'] ?? null) === 'accounting_journal_entries.firm_id'
                && ($where['operator'] ?? null) === '='
                && ($where['value'] ?? null) === $firmA->id,
        );

        $this->assertTrue($matchingWheres->isNotEmpty());
    }

    public function test_a_view_page_renders_successfully(): void
    {
        [$firm, $entry] = $this->makeFirmWithPostedEntry();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($entry): void {
            $test = Livewire::test(ViewAccountingJournalEntry::class, ['record' => $entry->getRouteKey()]);
            $test->assertSuccessful();
        });
    }
}
