<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Livewire\FinancialEvidence\FinancialEvidenceNotesPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceOverviewPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceReportsPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceReviewQueuesPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceSnapshotsPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceSummaryPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceTransactionSearchPanel;
use App\Services\MatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * FinancialEvidenceRelationManager — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on"). This is the
 * Financial Evidence Workspace's real content
 * (checkpoint4-design-workspace-and-admin-ui.md §1.1;
 * checkpoint4-combined-design.md §9.1), replacing the Matter/Client-
 * Portal track's structural stub body — same class identity, namespace,
 * and `canViewForRecord()` gate (unchanged from that track's own
 * design), only `content()` is now overridden.
 *
 * Confirmed directly against the installed Filament 4.11.8 source
 * (`vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php`
 * lines 330-339): `content()`'s DEFAULT body is itself just
 * `[TabsContentComponent, RenderHook, EmbeddedTable::make(), RenderHook]`
 * — not a hard constraint of the base class — so overriding it to
 * render an internal `Tabs::make()` of seven independent, embedded
 * Livewire panels is a fully supported override, never a workaround.
 * Because this override never calls `EmbeddedTable::make()`/`table()`/
 * `getRelationship()` at all, the stub's own placeholder logic is
 * entirely removed here (dead code the moment this class lands, per
 * that stub's own docblock).
 */
class FinancialEvidenceRelationManager extends RelationManager
{
    protected static ?string $title = 'Financial Evidence';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord);
    }

    public function content(Schema $schema): Schema
    {
        $matterId = $this->getOwnerRecord()->id;

        return $schema->components([
            Tabs::make('financial_evidence_workspace')
                ->tabs([
                    Tab::make('Overview')
                        ->schema([Livewire::make(FinancialEvidenceOverviewPanel::class, ['matterId' => $matterId])]),
                    Tab::make('Summaries')
                        ->schema([Livewire::make(FinancialEvidenceSummaryPanel::class, ['matterId' => $matterId])]),
                    Tab::make('Transactions')
                        ->schema([Livewire::make(FinancialEvidenceTransactionSearchPanel::class, ['matterId' => $matterId])]),
                    Tab::make('Review Queues')
                        ->schema([Livewire::make(FinancialEvidenceReviewQueuesPanel::class, ['matterId' => $matterId])]),
                    Tab::make('Attorney Notes')
                        ->schema([Livewire::make(FinancialEvidenceNotesPanel::class, ['matterId' => $matterId])]),
                    Tab::make('Evidence Snapshots')
                        ->schema([Livewire::make(FinancialEvidenceSnapshotsPanel::class, ['matterId' => $matterId])]),
                    Tab::make('Reports')
                        ->schema([Livewire::make(FinancialEvidenceReportsPanel::class, ['matterId' => $matterId])]),
                ]),
        ]);
    }
}
