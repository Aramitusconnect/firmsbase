<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Enums\LegalHoldScope;
use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Services\LegalHoldService;
use App\Services\TenantContextService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * FinancialEvidenceOverviewPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.4). Connected
 * Financial Sources, Date-Range Authorization, and the Access
 * Expiration banner — the Financial Evidence Workspace's "Overview" tab.
 *
 * Gated by BOTH GatesFinancialEvidenceMatterAccess (matter access) and
 * FinancialIntegrationAccessPolicyService::assertCanView() (financial
 * tier) — see GatesFinancialEvidenceMatterAccess::gateFinancialTierAccess().
 * Institution names, account names and masks are financial-tier data;
 * matter assignment alone never entitles a Paralegal/LegalAssistant/
 * Receptionist to them.
 */
class FinancialEvidenceOverviewPanel extends Component implements HasSchemas, HasTable
{
    use GatesFinancialEvidenceMatterAccess;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(int $matterId): void
    {
        $this->gateMatterAccess($matterId);
        $this->gateFinancialTierAccess($this->matter());
    }

    public function content(Schema $schema): Schema
    {
        // C2 remediation — the financial tier is re-asserted on every
        // render, not only at mount, so a mid-session role change takes
        // effect on the very next request.
        $this->gatedMatter();

        return $schema->components([
            $this->dateRangeAuthorizationSection(),
            $this->accessExpirationCallout(),
            EmbeddedTable::make(),
        ]);
    }

    private function dateRangeAuthorizationSection(): Section
    {
        $matter = $this->gatedMatter();

        $authorization = (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $matter->id)
            ->whereNull('superseded_at')
            ->latest('id')
            ->first());

        return Section::make('Date-Range Authorization')
            ->description('The currently-authorized retrieval window for this matter\'s connected financial data.')
            ->schema([
                TextEntry::make('start')
                    ->label('From')
                    ->state($authorization?->authorized_date_range_start?->toFormattedDateString() ?? 'No lower bound'),
                TextEntry::make('end')
                    ->label('To')
                    ->state($authorization?->authorized_date_range_end?->toFormattedDateString() ?? 'No upper bound'),
                TextEntry::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->state(FinancialEvidenceProvenance::ProviderSuppliedFact->label())
                    ->color(FinancialEvidenceProvenance::ProviderSuppliedFact->badgeColor()),
            ])
            ->columns(3);
    }

    private function accessExpirationCallout(): Callout
    {
        $matter = $this->gatedMatter();

        $hasHold = app(LegalHoldService::class)->hasActiveHold($matter->firm, LegalHoldScope::Matter, $matter->id);

        if ($hasHold) {
            return Callout::make('Legal hold active')
                ->description('Retention/expiration is suspended by an active legal hold on this matter.')
                ->color('info');
        }

        $connections = (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $matter->id)
            ->whereNull('superseded_at')
            ->with('firmIntegration')
            ->get());

        $statuses = $connections->pluck('firmIntegration.status')->map(fn ($s) => is_object($s) ? $s->value : $s);

        if ($statuses->isEmpty()) {
            return Callout::make('No financial sources connected')
                ->description('No account has been authorized for this matter yet.')
                ->color('gray');
        }

        if ($statuses->contains('reauthorization_required') || $statuses->contains('disconnected')) {
            return Callout::make('Access revoked or paused')
                ->description('One or more connections require reauthorization or have been disconnected. Only last-known evidence is available until reconnected.')
                ->color('danger');
        }

        if ($statuses->contains('scope_insufficient') || $statuses->contains('error')) {
            return Callout::make('Access expiring soon')
                ->description('One or more connections need attention. Request renewal via the client to keep evidence current.')
                ->color('warning');
        }

        return Callout::make('Access in good standing')
            ->description('All connected financial sources are currently active.')
            ->color('success');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $matter = $this->gatedMatter();
                $firmIntegrationIds = (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceMatterAuthorization::query()
                    ->where('matter_id', $matter->id)
                    ->whereNull('superseded_at')
                    ->pluck('firm_integration_id')
                    ->unique());

                if ($firmIntegrationIds->isEmpty()) {
                    return collect();
                }

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceBankAccount::query()
                    ->whereIn('firm_integration_id', $firmIntegrationIds)
                    ->with('firmIntegration.integrationProvider')
                    ->get()
                    ->map(fn (FinancialEvidenceBankAccount $account): array => [
                        'id' => $account->id,
                        'institution' => $account->firmIntegration?->integrationProvider?->display_name ?? 'Unknown institution',
                        'account_name' => $account->account_name ?? 'Untitled account',
                        'mask' => $account->mask,
                        'classification' => $account->classification,
                        'status' => is_object($account->firmIntegration?->status) ? $account->firmIntegration->status->value : $account->firmIntegration?->status,
                    ]));
            })
            ->columns([
                TextColumn::make('institution')->label('Institution'),
                TextColumn::make('account_name')->label('Account'),
                TextColumn::make('mask')->label('Mask')->placeholder('—'),
                TextColumn::make('classification')->label('Classification')->badge()->placeholder('Unclassified'),
                TextColumn::make('status')
                    ->label('Connection status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'scope_insufficient', 'reauthorization_required' => 'warning',
                        'error' => 'danger',
                        'disconnected' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->state(FinancialEvidenceProvenance::ProviderSuppliedFact->label())
                    ->color(FinancialEvidenceProvenance::ProviderSuppliedFact->badgeColor()),
            ])
            ->heading('Connected Financial Sources')
            ->emptyStateHeading('No financial sources connected')
            ->emptyStateDescription('Request a financial-data connection from the client via "Matter financial-evidence requests."')
            ->paginated(false);
    }

    public function render()
    {
        $this->gatedMatter();

        return view('livewire.financial-evidence.overview-panel');
    }
}
