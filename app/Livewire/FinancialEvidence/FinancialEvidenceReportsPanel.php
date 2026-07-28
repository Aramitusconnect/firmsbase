<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceSnapshot;
use App\Models\FinancialEvidenceTransactionReview;
use App\Services\TenantContextService;
use Barryvdh\DomPDF\Facade\Pdf;
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
use Illuminate\Support\Facades\Auth;
use League\Csv\Writer;
use Livewire\Component;
use RuntimeException;

/**
 * FinancialEvidenceReportsPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.11). Export action,
 * PDF and CSV, gated by
 * `FinancialIntegrationAccessPolicyService::assertCanView()` (view-tier
 * — exporting an already-authorized view is not a new grant). Every
 * export MUST originate from an existing `financial_evidence_snapshots`
 * row, never a live re-query.
 *
 * PDF dependency decision: `barryvdh/laravel-dompdf` (^3.1) — per
 * checkpoint4-combined-design.md §1.1.4's confirmed finding that no
 * PDF-rendering capability of any kind existed in this codebase before
 * this checkpoint. A small, well-established Laravel-ecosystem package,
 * added as a direct `composer.json` dependency by this same track (see
 * the accompanying report for the exact version pinned).
 */
class FinancialEvidenceReportsPanel extends Component implements HasActions, HasSchemas, HasTable
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
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): \Illuminate\Support\Collection {
                $matter = $this->matter();

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceSnapshot::query()
                    ->where('matter_id', $matter->id)
                    ->orderByDesc('report_version')
                    ->get()
                    ->map(fn (FinancialEvidenceSnapshot $s): array => [
                        'id' => $s->id,
                        'report_version' => $s->report_version,
                        'source_product' => $s->source_product,
                        'created_at' => $s->created_at?->toDayDateTimeString(),
                    ]));
            })
            ->columns([
                TextColumn::make('report_version')->label('Version'),
                TextColumn::make('source_product')->label('Product'),
                TextColumn::make('created_at')->label('Generated at'),
            ])
            ->recordActions([
                Action::make('exportPdf')
                    ->label('Export PDF')
                    ->action(fn (array $record) => $this->exportPdf((int) $record['id'])),
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->color('gray')
                    ->action(fn (array $record) => $this->exportCsv((int) $record['id'])),
            ])
            ->emptyStateHeading('No snapshots to export')
            ->emptyStateDescription('Generate a snapshot in the Evidence Snapshots tab first — every export originates from an existing snapshot, never a live re-query.')
            ->paginated(false);
    }

    private function loadSnapshotOrFail(int $snapshotId): FinancialEvidenceSnapshot
    {
        $matter = $this->matter();
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            throw new RuntimeException('No active firm membership.');
        }

        app(FinancialIntegrationAccessPolicyService::class)->assertCanView($firmUser);

        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $snapshotId) {
            $snapshot = FinancialEvidenceSnapshot::query()
                ->where('matter_id', $matter->id)
                ->where('id', $snapshotId)
                ->first();

            if ($snapshot === null) {
                throw new RuntimeException('Snapshot not found for this matter.');
            }

            return $snapshot;
        });
    }

    /**
     * "Reviewer status" — mission spec §23's required export field,
     * absent from `financial_evidence_snapshots` itself (a snapshot is
     * an immutable fact row, never edited to carry a review outcome).
     * Derived, read-only, from the SAME source
     * `FinancialEvidenceTransactionSearchPanel` already uses to compute
     * its own per-row `reviewed` flag: the latest
     * `financial_evidence_transaction_reviews` row per transaction
     * referenced by this snapshot's `retrieved_record_refs_json`. Never
     * written back to the snapshot — a fresh export re-derives this
     * from current review state, exactly like any other "as of export
     * time" summary field.
     */
    private function reviewerStatusSummary(FinancialEvidenceSnapshot $snapshot): string
    {
        return (new TenantContextService)->runWithFirmContext($snapshot->firm_id, function () use ($snapshot): string {
            $transactionIds = collect($snapshot->retrieved_record_refs_json ?? [])
                ->where('type', 'financial_evidence_transactions')
                ->pluck('id');

            if ($transactionIds->isEmpty()) {
                return 'Not applicable — this snapshot references no transaction records.';
            }

            $reviewedCount = FinancialEvidenceTransactionReview::query()
                ->whereIn('transaction_id', $transactionIds)
                ->pluck('transaction_id')
                ->unique()
                ->count();

            if ($reviewedCount === 0) {
                return 'Not yet reviewed';
            }

            return "{$reviewedCount} of {$transactionIds->count()} referenced transactions reviewed";
        });
    }

    public function exportPdf(int $snapshotId): mixed
    {
        try {
            $snapshot = $this->loadSnapshotOrFail($snapshotId);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }

        $pdf = Pdf::loadView('financial-evidence.reports.snapshot-pdf', [
            'snapshot' => $snapshot,
            'matter' => $this->matter(),
            'reviewerStatus' => $this->reviewerStatusSummary($snapshot),
        ]);

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf->output();
            },
            "financial-evidence-report-v{$snapshot->report_version}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function exportCsv(int $snapshotId): mixed
    {
        try {
            $snapshot = $this->loadSnapshotOrFail($snapshotId);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }

        $csv = Writer::createFromString('');
        $csv->insertOne(['Field', 'Value']);
        $csv->insertOne(['Matter', $this->matter()->uuid ?? (string) $this->matter()->id]);
        $csv->insertOne(['Report version', (string) $snapshot->report_version]);
        $csv->insertOne(['Source product', $snapshot->source_product]);
        $csv->insertOne(['Date range start', (string) $snapshot->date_range_start]);
        $csv->insertOne(['Date range end', (string) $snapshot->date_range_end]);
        $csv->insertOne(['Provider retrieved at', (string) $snapshot->provider_retrieved_at]);
        $csv->insertOne(['Generated at', (string) $snapshot->created_at]);
        $csv->insertOne(['Checksum', (string) ($snapshot->checksum ?? 'None')]);
        $csv->insertOne(['Checksum source', (string) ($snapshot->checksum_source ?? 'None')]);
        $csv->insertOne(['Consent reference', (string) ($snapshot->consent_id ?? 'None')]);
        $csv->insertOne(['Reviewer status', $this->reviewerStatusSummary($snapshot)]);
        $csv->insertOne(['Limitations', $snapshot->limitations_text]);
        $csv->insertOne(['Included record count', (string) count($snapshot->retrieved_record_refs_json ?? [])]);

        $content = $csv->toString();

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, "financial-evidence-report-v{$snapshot->report_version}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render()
    {
        return view('livewire.financial-evidence.reports-panel');
    }
}
