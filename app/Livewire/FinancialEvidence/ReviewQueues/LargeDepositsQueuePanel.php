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
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * LargeDepositsQueuePanel — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Display-only,
 * FirmsVaultObservation provenance.
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
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $matter = $this->matter();

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

    private function resolveFlag(int $flagId, bool $dismissed): void
    {
        $matter = $this->matter();
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($flagId, $dismissed, $firmUser) {
            $flag = FinancialEvidenceLargeDepositFlag::query()->find($flagId);

            if ($flag === null) {
                return;
            }

            $flag->update($dismissed
                ? ['dismissed_at' => now(), 'dismissed_by_firm_user_id' => $firmUser->id]
                : ['confirmed_at' => now(), 'confirmed_by_firm_user_id' => $firmUser->id]);
        });

        Notification::make()->title($dismissed ? 'Dismissed' : 'Confirmed reviewed')->success()->send();
    }

    public function render()
    {
        return view('livewire.financial-evidence.review-queues.large-deposits-queue-panel');
    }
}
