<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Integrations\Services\FinancialEvidenceMatterScopeService;
use App\Integrations\Services\FinancialEvidenceRecurringObligationDetectionService;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceIncomeRecord;
use App\Models\FinancialEvidenceInvestmentRecord;
use App\Models\FinancialEvidenceLiability;
use App\Services\TenantContextService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * FinancialEvidenceSummaryPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.5). Income Summary,
 * Recurring Obligations, Liability Summary, Investment Summary — four
 * `Section`-composed sub-panes, mirroring `ViewFirmIntegration`'s own
 * `Section::make(...)->columns(2)` Infolist idiom.
 */
class FinancialEvidenceSummaryPanel extends Component implements HasSchemas
{
    use GatesFinancialEvidenceMatterAccess;
    use InteractsWithSchemas;

    public function mount(int $matterId): void
    {
        $this->gateMatterAccess($matterId);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->incomeSection(),
            $this->recurringObligationsSection(),
            $this->liabilitySection(),
            $this->investmentSection(),
        ]);
    }

    private function incomeSection(): Section
    {
        $matter = $this->matter();
        $firmIntegrationIds = app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter);

        $records = $firmIntegrationIds === []
            ? collect()
            : (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceIncomeRecord::query()
                ->whereIn('firm_integration_id', $firmIntegrationIds)
                ->get());

        return Section::make('Income Summary')
            ->description('Bank Income product data, grouped by category and pay frequency.')
            ->schema([
                RepeatableEntry::make('records')
                    ->state($records->map(fn (FinancialEvidenceIncomeRecord $r): array => [
                        'category' => $r->category ?? 'Uncategorized',
                        'pay_frequency' => $r->pay_frequency ?? '—',
                        'provenance' => FinancialEvidenceProvenance::ProviderSuppliedFact->label(),
                    ])->all())
                    ->schema([
                        TextEntry::make('category')->label('Category'),
                        TextEntry::make('pay_frequency')->label('Pay frequency'),
                        TextEntry::make('provenance')->badge()->color(FinancialEvidenceProvenance::ProviderSuppliedFact->badgeColor()),
                    ])
                    ->columns(3),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    private function recurringObligationsSection(): Section
    {
        $matter = $this->matter();
        $obligations = app(FinancialEvidenceRecurringObligationDetectionService::class)->detect($matter);

        return Section::make('Recurring Obligations')
            ->description('A FirmsVault-generated observation (not a Plaid product) — grouped by merchant, amount tolerance, and cadence.')
            ->schema([
                RepeatableEntry::make('obligations')
                    ->state($obligations->map(fn (array $o): array => [
                        'merchant_name' => $o['merchant_name'],
                        'occurrences' => (string) $o['occurrences'],
                        'average_amount' => '$'.number_format($o['average_amount_cents'] / 100, 2),
                        'last_transaction_date' => $o['last_transaction_date'],
                        'provenance' => FinancialEvidenceProvenance::FirmsVaultObservation->label(),
                    ])->all())
                    ->schema([
                        TextEntry::make('merchant_name')->label('Merchant'),
                        TextEntry::make('occurrences')->label('Occurrences'),
                        TextEntry::make('average_amount')->label('Avg. amount'),
                        TextEntry::make('last_transaction_date')->label('Last seen'),
                        TextEntry::make('provenance')->badge()->color(FinancialEvidenceProvenance::FirmsVaultObservation->badgeColor()),
                    ])
                    ->columns(5),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    private function liabilitySection(): Section
    {
        $matter = $this->matter();
        $firmIntegrationIds = app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter);

        $liabilities = $firmIntegrationIds === []
            ? collect()
            : (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceLiability::query()
                ->whereIn('firm_integration_id', $firmIntegrationIds)
                ->get());

        return Section::make('Liability Summary')
            ->description('Never force-merged across liability types (credit/mortgage/student are genuinely different shapes).')
            ->schema([
                RepeatableEntry::make('liabilities')
                    ->state($liabilities->map(fn (FinancialEvidenceLiability $l): array => [
                        'liability_type' => ucfirst($l->liability_type),
                        'provenance' => FinancialEvidenceProvenance::ProviderSuppliedFact->label(),
                    ])->all())
                    ->schema([
                        TextEntry::make('liability_type')->label('Type'),
                        TextEntry::make('provenance')->badge()->color(FinancialEvidenceProvenance::ProviderSuppliedFact->badgeColor()),
                    ])
                    ->columns(2),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    private function investmentSection(): Section
    {
        $matter = $this->matter();
        $firmIntegrationIds = app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter);

        $holdings = $firmIntegrationIds === []
            ? collect()
            : (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceInvestmentRecord::query()
                ->whereIn('firm_integration_id', $firmIntegrationIds)
                ->where('record_type', 'holding')
                ->get());

        return Section::make('Investment Summary')
            ->description('Holdings only — investment transactions surface in the Transactions panel.')
            ->schema([
                RepeatableEntry::make('holdings')
                    ->state($holdings->map(fn (FinancialEvidenceInvestmentRecord $h): array => [
                        'security_id' => $h->plaid_security_id ?? '—',
                        'provenance' => FinancialEvidenceProvenance::ProviderSuppliedFact->label(),
                    ])->all())
                    ->schema([
                        TextEntry::make('security_id')->label('Security'),
                        TextEntry::make('provenance')->badge()->color(FinancialEvidenceProvenance::ProviderSuppliedFact->badgeColor()),
                    ])
                    ->columns(2),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    public function render()
    {
        return view('livewire.financial-evidence.summary-panel');
    }
}
