<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence\ReviewQueues;

use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceLargeDepositFlag;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
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
 * LargeDepositsQueuePanel — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Display-only,
 * FirmsVaultObservation provenance.
 *
 * Gated by BOTH GatesFinancialEvidenceMatterAccess (matter access) and
 * FinancialIntegrationAccessPolicyService::assertCanView() (financial
 * tier) — see GatesFinancialEvidenceMatterAccess::gateFinancialTierAccess().
 * `resolveFlag()` re-runs both gates independently rather than trusting
 * that the page was reachable, and resolves the submitted flag id
 * through a firm_id + matter_id constrained query (never
 * `::find($id)`), so a client-supplied id can never reach another
 * matter's or another firm's row.
 */
class LargeDepositsQueuePanel extends Component implements HasActions, HasSchemas, HasTable
{
    use GatesFinancialEvidenceMatterAccess;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(int $matterId): void
    {
        $this->gateMatterAccess($matterId);
        $this->gateFinancialTierAccess($this->matter());
    }

    public function content(Schema $schema): Schema
    {
        // C2 remediation — re-asserted on every render, not only at mount.
        $this->gatedMatter();

        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        // NOTE: the gate deliberately lives in the data-producing
        // closure below (and in resolveFlag()), not in this builder
        // body — `table()` is invoked during schema construction,
        // before any tenant context exists, and produces no rows
        // itself.
        return $table
            ->records(function (): Collection {
                $matter = $this->gatedMatter();

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceLargeDepositFlag::query()
                    ->where('matter_id', $matter->id)
                    ->whereNull('dismissed_at')
                    ->whereNull('confirmed_at')
                    ->with('transaction')
                    ->orderByDesc('detected_at')
                    ->get()
                    ->map(fn (FinancialEvidenceLargeDepositFlag $f): array => [
                        'id' => $f->id,
                        'merchant_name' => $f->transaction?->merchant_name ?? '—',
                        'amount' => $f->transaction !== null ? number_format($f->transaction->amount_cents / 100, 2) : '—',
                        'threshold' => number_format($f->threshold_cents_applied / 100, 2),
                        'detected_at' => $f->detected_at?->toDayDateTimeString(),
                    ]));
            })
            ->columns([
                TextColumn::make('merchant_name')->label('Source'),
                TextColumn::make('amount')->label('Amount')->alignEnd(),
                TextColumn::make('threshold')->label('Threshold applied')->alignEnd(),
                TextColumn::make('detected_at')->label('Detected'),
                TextColumn::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->state(FinancialEvidenceProvenance::FirmsVaultObservation->label())
                    ->color(FinancialEvidenceProvenance::FirmsVaultObservation->badgeColor()),
            ])
            ->recordActions([
                Action::make('dismiss')->label('Dismiss')->color('gray')
                    ->action(fn (array $record) => $this->resolveFlag((int) $record['id'], dismissed: true)),
                Action::make('confirm')->label('Confirm reviewed')->color('warning')
                    ->action(fn (array $record) => $this->resolveFlag((int) $record['id'], dismissed: false)),
            ])
            ->emptyStateHeading('No unexplained large deposits')
            ->paginated(false);
    }

    /**
     * C2 + H2 remediation. Every mutation re-runs the matter-access AND
     * financial-tier gates itself, then resolves the submitted id
     * through a firm_id + matter_id constrained query — never
     * `::find($flagId)` followed by an authorization check that only
     * covers the page's own matter (which let an attorney authorized
     * for Matter A tamper with the id and resolve Matter B's flag).
     */
    private function resolveFlag(int $flagId, bool $dismissed): void
    {
        [$matter, $firmUser] = $this->gatedFinancialEvidenceContext();

        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $flagId, $dismissed, $firmUser) {
            $flag = FinancialEvidenceLargeDepositFlag::query()
                ->where('firm_id', $matter->firm_id)
                ->where('matter_id', $matter->id)
                ->where('id', $flagId)
                ->firstOrFail();

            $flag->update($dismissed
                ? ['dismissed_at' => now(), 'dismissed_by_firm_user_id' => $firmUser->id]
                : ['confirmed_at' => now(), 'confirmed_by_firm_user_id' => $firmUser->id]);
        });

        Notification::make()->title($dismissed ? 'Dismissed' : 'Confirmed reviewed')->success()->send();
    }

    public function render()
    {
        $this->gatedMatter();

        return view('livewire.financial-evidence.review-queues.large-deposits-queue-panel');
    }
}
