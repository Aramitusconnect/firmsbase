<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence\ReviewQueues;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceReconciliationCandidate;
use App\Models\TrustLedgerEntry;
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
 * ReconciliationCandidatesQueuePanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). EXPLICITLY NEVER
 * AUTO-POSTS TO THE TRUST LEDGER; display-only, attorney-decision-
 * driven. `trustLedgerEntry` display data is looked up via a plain,
 * read-only `TrustLedgerEntry::query()->find()` call — this class is
 * never itself named `Trust*`, and never writes to `TrustLedgerEntry`
 * or calls any `Trust*` SERVICE. The one action here ("Confirm as
 * ledger match") is attorney-only and writes ONLY to
 * `financial_evidence_reconciliation_candidates.status`.
 */
class ReconciliationCandidatesQueuePanel extends Component implements HasActions, HasSchemas, HasTable
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

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter) {
                    $candidates = FinancialEvidenceReconciliationCandidate::query()
                        ->where('matter_id', $matter->id)
                        ->where('status', 'candidate')
                        ->with('transaction')
                        ->orderByDesc('id')
                        ->get();

                    // Read-only display lookup only — never a write, never
                    // a Trust* SERVICE call.
                    $ledgerEntries = TrustLedgerEntry::query()
                        ->whereIn('id', $candidates->pluck('trust_ledger_entry_id')->filter())
                        ->get(['id', 'amount_cents'])
                        ->keyBy('id');

                    return $candidates->map(function (FinancialEvidenceReconciliationCandidate $c) use ($ledgerEntries): array {
                        $ledgerEntry = $c->trust_ledger_entry_id !== null ? $ledgerEntries->get($c->trust_ledger_entry_id) : null;

                        return [
                            'id' => $c->id,
                            'merchant_name' => $c->transaction?->merchant_name ?? '—',
                            'amount' => $c->transaction !== null ? number_format($c->transaction->amount_cents / 100, 2) : '—',
                            'ledger_entry_amount' => $ledgerEntry !== null ? number_format($ledgerEntry->amount_cents / 100, 2) : '—',
                            'confidence' => ucfirst($c->match_confidence),
                        ];
                    });
                });
            })
            ->columns([
                TextColumn::make('merchant_name')->label('Transaction'),
                TextColumn::make('amount')->label('Amount')->alignEnd(),
                TextColumn::make('ledger_entry_amount')->label('Ledger entry amount (read-only)')->alignEnd(),
                TextColumn::make('confidence')->label('Match confidence')->badge(),
                TextColumn::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->state(FinancialEvidenceProvenance::ReconciliationCandidate->label())
                    ->color(FinancialEvidenceProvenance::ReconciliationCandidate->badgeColor()),
            ])
            ->recordActions([
                Action::make('reject')->label('Reject')->color('gray')
                    ->action(fn (array $record) => $this->decide((int) $record['id'], 'rejected')),
                Action::make('confirm')
                    ->label('Confirm as ledger match')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Auth::user()?->activeFirmUser()?->role === FirmUserRole::Attorney)
                    ->action(fn (array $record) => $this->decide((int) $record['id'], 'confirmed_match')),
            ])
            ->emptyStateHeading('No reconciliation candidates')
            ->emptyStateDescription('This queue is display-only and attorney-decision-driven — it never posts to the trust ledger.')
            ->paginated(false);
    }

    private function decide(int $candidateId, string $status): void
    {
        $matter = $this->matter();
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        if ($status === 'confirmed_match' && $firmUser->role !== FirmUserRole::Attorney) {
            Notification::make()->title('Only an Attorney may confirm a ledger match')->danger()->send();

            return;
        }

        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($candidateId, $status, $firmUser) {
            $candidate = FinancialEvidenceReconciliationCandidate::query()->find($candidateId);

            if ($candidate === null) {
                return;
            }

            // Writes ONLY to this table — never trust_ledger_entries or
            // any Trust* table.
            $candidate->update([
                'status' => $status,
                'reviewed_by_firm_user_id' => $firmUser->id,
                'reviewed_at' => now(),
            ]);
        });

        Notification::make()->title($status === 'confirmed_match' ? 'Confirmed as ledger match' : 'Rejected')->success()->send();
    }

    public function render()
    {
        return view('livewire.financial-evidence.review-queues.reconciliation-candidates-queue-panel');
    }
}
