<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * PlaidRequestReviewPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.1). First screen the
 * client sees if a pending financial-data request exists — reads the
 * requesting matter/firm/purpose from a `financial_evidence_matter_requests`
 * row, created by the firm's own `PlaidMatterRequestsPage`. Every row
 * is resolved exclusively through
 * `ClientPortalMatterAccessPolicyService::canAccessMatter()`'s already-
 * scoped grant list — this design adds zero new authorization mechanism.
 */
class PlaidRequestReviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Financial Data Requests';

    protected static ?string $title = 'Financial Data Requests';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                /** @var ClientPortalUser|null $portalUser */
                $portalUser = Auth::guard('client')->user();

                if ($portalUser === null) {
                    return collect();
                }

                $grantedMatterIds = app(ClientPortalMatterAccessPolicyService::class)->grantedMatterIds($portalUser);

                if ($grantedMatterIds === []) {
                    return collect();
                }

                return (new TenantContextService)->runWithFirmContext($portalUser->client->firm_id, fn () => FinancialEvidenceMatterRequest::query()
                    ->whereIn('matter_id', $grantedMatterIds)
                    ->where('status', 'pending')
                    ->orderByDesc('requested_at')
                    ->get());
            })
            ->columns([
                TextColumn::make('purpose')->label('Purpose')->wrap(),
                TextColumn::make('requested_products_json')->label('Requested')->formatStateUsing(fn (?array $state): string => $state !== null ? implode(', ', $state) : '—'),
                TextColumn::make('requested_at')->label('Requested')->dateTime(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review & Connect')
                    ->url(fn (FinancialEvidenceMatterRequest $record): string => PlaidAccountSelectionPage::getUrl(['matter' => $record->matter_id])),
            ])
            ->emptyStateHeading('No pending financial data requests')
            ->paginated(false);
    }
}
