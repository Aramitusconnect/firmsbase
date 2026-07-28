<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Livewire\FinancialEvidence\ReviewQueues\DuplicateTransfersQueuePanel;
use App\Livewire\FinancialEvidence\ReviewQueues\LargeDepositsQueuePanel;
use App\Livewire\FinancialEvidence\ReviewQueues\ReconciliationCandidatesQueuePanel;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * FinancialEvidenceReviewQueuesPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Three sub-tabs,
 * all FirmsVault-generated observations, all display-only: Potential
 * Duplicate Transfers, Unexplained Large Deposits, Reconciliation
 * Candidates. Nested `Tabs` inside this panel's own `content()` — the
 * same mechanism `FinancialEvidenceRelationManager::content()` itself
 * uses, one level deeper — each pane its own independent, embedded
 * Livewire sub-panel (each with its own `HasTable`, since one
 * `HasTable` class can bind only one `table()` definition).
 */
class FinancialEvidenceReviewQueuesPanel extends Component implements HasSchemas
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
            Tabs::make('financial_evidence_review_queues')
                ->tabs([
                    Tab::make('Potential Duplicate Transfers')
                        ->schema([Livewire::make(DuplicateTransfersQueuePanel::class, ['matterId' => $this->matterId])]),
                    Tab::make('Unexplained Large Deposits')
                        ->schema([Livewire::make(LargeDepositsQueuePanel::class, ['matterId' => $this->matterId])]),
                    Tab::make('Reconciliation Candidates')
                        ->schema([Livewire::make(ReconciliationCandidatesQueuePanel::class, ['matterId' => $this->matterId])]),
                ]),
        ]);
    }

    public function render()
    {
        return view('livewire.financial-evidence.review-queues-panel');
    }
}
