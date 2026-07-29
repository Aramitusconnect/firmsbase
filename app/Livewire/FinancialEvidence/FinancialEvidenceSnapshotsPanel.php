<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Services\FinancialEvidenceMatterScopeService;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceSnapshot;
use App\Models\FinancialEvidenceTransaction;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Component;
use RuntimeException;

/**
 * FinancialEvidenceSnapshotsPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.9). Immutable, one
 * row per snapshot-creation event. "Create Snapshot" opens a Filament
 * wizard action (mirrors `ConnectProviderAction`'s `Action::steps()`
 * shape) with an explicit review step before the irreversible
 * "Generate" submit.
 *
 * H3 remediation: the wizard's CheckboxList options are scoped to
 * `FinancialEvidenceMatterScopeService::connectedBankAccountIds()` for
 * DISPLAY, but display scoping is not authorization — the SUBMITTED
 * `bank_account_ids` are attacker-controlled (a tampered Livewire
 * payload, or the public property set directly). `generateSnapshot()`
 * therefore RE-DERIVES the allowlist server-side at submit time and
 * rejects the whole request if any submitted id falls outside it, so a
 * user with only Matter A access can never bake Matter B's (or another
 * firm's, or another client's) accounts into an immutable, exportable
 * snapshot.
 */
class FinancialEvidenceSnapshotsPanel extends Component implements HasActions, HasSchemas, HasTable
{
    use GatesFinancialEvidenceMatterAccess;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    private const DEFAULT_LIMITATIONS = 'Historical data limited by Plaid\'s documented retrieval window; '
        .'balance figures reflect a point-in-time retrieval, not a real-time guarantee.';

    public function mount(int $matterId): void
    {
        $this->gateMatterAccess($matterId);
        $this->gateFinancialTierAccess($this->matter());
    }

    public function content(Schema $schema): Schema
    {
        $this->gatedMatter();

        return $schema->components([
            SchemaActions::make([$this->createSnapshotAction()]),
            EmbeddedTable::make(),
        ]);
    }

    private function createSnapshotAction(): Action
    {
        return Action::make('createSnapshot')
            ->label('Create Snapshot')
            ->steps([
                Step::make('Configure')
                    ->schema([
                        Select::make('source_product')
                            ->label('Source product')
                            ->options([
                                'transactions' => 'Transactions',
                                'bank_account' => 'Accounts',
                                'income' => 'Income',
                                'liabilities' => 'Liabilities',
                                'investments' => 'Investments',
                                'balance' => 'Balance',
                            ])
                            ->required(),
                        CheckboxList::make('bank_account_ids')
                            ->label('Accounts to include')
                            ->options(function (): array {
                                // DISPLAY scoping only — never relied on as
                                // authorization; generateSnapshot() re-derives
                                // this exact allowlist at submit time.
                                $matter = $this->gatedMatter();
                                $bankAccountIds = app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($matter);

                                return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceBankAccount::query()
                                    ->whereIn('id', $bankAccountIds)
                                    ->get()
                                    ->mapWithKeys(fn (FinancialEvidenceBankAccount $a) => [$a->id => $a->account_name ?? "Account #{$a->id}"])
                                    ->all());
                            })
                            ->required(),
                    ]),
                Step::make('Review')
                    ->schema([
                        TextEntry::make('review_notice')
                            ->hiddenLabel()
                            ->state('Reviewing this snapshot before it is generated — once created, a snapshot is immutable and cannot be edited or deleted.'),
                        Textarea::make('limitations_text')
                            ->label('Limitations')
                            ->default(self::DEFAULT_LIMITATIONS)
                            ->rows(3)
                            ->required(),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->generateSnapshot($data);
            });
    }

    private function generateSnapshot(array $data): void
    {
        [$matter, $firmUser] = $this->gatedFinancialEvidenceContext();

        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $firmUser, $data) {
            // ------------------------------------------------------------
            // H3 — authorize the SUBMITTED account set, server-side.
            // ------------------------------------------------------------
            $submittedBankAccountIds = array_values(array_unique(
                array_map('intval', $data['bank_account_ids'] ?? [])
            ));

            // Re-derived HERE, at submit time — never the set that was
            // rendered into the wizard, which the client controls.
            $authorizedBankAccountIds = array_map(
                'intval',
                app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($matter)
            );

            $bankAccountIds = array_values(array_intersect($submittedBankAccountIds, $authorizedBankAccountIds));

            // Reject the WHOLE request on any unauthorized id — never
            // silently drop it and still report success. Whole-request
            // rejection matches this codebase's existing convention
            // (FinancialEvidenceReportsPanel::loadSnapshotOrFail()
            // refuses the entire export rather than exporting a partial
            // one).
            $rejected = array_values(array_diff($submittedBankAccountIds, $authorizedBankAccountIds));

            if ($rejected !== []) {
                throw new RuntimeException(
                    'One or more selected accounts are not authorized for this matter: '
                    .implode(', ', $rejected).'. No snapshot was generated.'
                );
            }

            // Accounts are a required part of the snapshot (the wizard
            // marks the CheckboxList ->required()); an empty resulting
            // set is a rejection, not an empty snapshot.
            if ($bankAccountIds === []) {
                throw new RuntimeException(
                    'At least one authorized account must be selected — no snapshot was generated.'
                );
            }

            // ------------------------------------------------------------
            // H3 — the authorization/consent chain must still be live.
            // ------------------------------------------------------------
            $authorization = FinancialEvidenceMatterAuthorization::query()
                ->where('firm_id', $matter->firm_id)
                ->where('matter_id', $matter->id)
                ->whereNull('superseded_at')
                ->latest('id')
                ->first();

            if ($authorization === null) {
                throw new RuntimeException(
                    'This matter has no current (non-superseded) financial-evidence authorization — no snapshot was generated.'
                );
            }

            // The existing consent concept (FinancialEvidenceClientConsent:
            // granted_at set / declined_at null — see its own isGranted()/
            // isDeclined()); no new consent concept is introduced here.
            // Constrained to this firm and matter so a consent row can
            // never be borrowed across matters.
            $consent = $authorization->consent_id !== null
                ? FinancialEvidenceClientConsent::query()
                    ->where('firm_id', $matter->firm_id)
                    ->where('matter_id', $matter->id)
                    ->where('id', $authorization->consent_id)
                    ->first()
                : null;

            if ($consent !== null && ($consent->isDeclined() || ! $consent->isGranted())) {
                throw new RuntimeException(
                    'The client consent backing this matter\'s financial evidence is declined or not granted — no snapshot was generated.'
                );
            }

            // Every authorized account must still hang off a connection
            // that is actually usable — a disconnected/reauthorization-
            // required connection must not be re-baked into a new
            // immutable snapshot.
            $bankAccounts = FinancialEvidenceBankAccount::query()
                ->where('firm_id', $matter->firm_id)
                ->whereIn('id', $bankAccountIds)
                ->with('firmIntegration')
                ->get();

            if ($bankAccounts->count() !== count($bankAccountIds)) {
                throw new RuntimeException(
                    'One or more selected accounts could not be resolved within this firm — no snapshot was generated.'
                );
            }

            $inactive = $bankAccounts
                ->filter(fn (FinancialEvidenceBankAccount $a): bool => $a->firmIntegration?->status !== ConnectionStatus::Active)
                ->pluck('id')
                ->all();

            if ($inactive !== []) {
                throw new RuntimeException(
                    'The connection backing one or more selected accounts is no longer active: '
                    .implode(', ', $inactive).'. No snapshot was generated.'
                );
            }

            $firmIntegrationIds = $bankAccounts->pluck('firm_integration_id')->unique();

            // ------------------------------------------------------------
            // Data pull — from the POST-INTERSECTION set only.
            // ------------------------------------------------------------
            $transactionQuery = fn () => FinancialEvidenceTransaction::query()
                ->where('firm_id', $matter->firm_id)
                ->whereIn('bank_account_id', $bankAccountIds)
                ->when(
                    $authorization->authorized_date_range_start !== null,
                    fn ($q) => $q->whereDate('transaction_date', '>=', $authorization->authorized_date_range_start)
                )
                ->when(
                    $authorization->authorized_date_range_end !== null,
                    fn ($q) => $q->whereDate('transaction_date', '<=', $authorization->authorized_date_range_end)
                );

            $transactionRefs = $transactionQuery()
                ->pluck('id')
                ->map(fn (int $id): array => ['type' => 'financial_evidence_transactions', 'id' => $id])
                ->all();

            $latestRetrievedAt = $transactionQuery()->max('provider_retrieved_at');

            $lastReportVersion = (int) FinancialEvidenceSnapshot::query()
                ->where('firm_id', $matter->firm_id)
                ->where('matter_id', $matter->id)
                ->max('report_version');

            $checksumSource = $transactionRefs === [] ? null : hash('sha256', json_encode($transactionRefs));

            FinancialEvidenceSnapshot::query()->create([
                'firm_id' => $matter->firm_id,
                'matter_id' => $matter->id,
                'generated_by_firm_user_id' => $firmUser->id,
                'consent_id' => $consent?->id,
                // Provenance records the VERIFIED, post-intersection set
                // — never the raw submitted set.
                'authorized_source_json' => ['firm_integration_ids' => $firmIntegrationIds->values()->all()],
                'authorized_account_ids_json' => $bankAccountIds,
                'date_range_start' => $authorization->authorized_date_range_start,
                'date_range_end' => $authorization->authorized_date_range_end,
                'retrieved_record_refs_json' => $transactionRefs,
                'provider_retrieved_at' => $latestRetrievedAt,
                'redacted_request_reference' => null,
                'source_product' => $data['source_product'],
                'report_version' => $lastReportVersion + 1,
                'checksum' => $checksumSource,
                'checksum_source' => $checksumSource !== null ? 'firmsvault_computed' : null,
                'limitations_text' => $data['limitations_text'] ?? self::DEFAULT_LIMITATIONS,
                'created_at' => now(),
            ]);
        });

        Notification::make()->title('Snapshot generated')->success()->send();
    }

    public function table(Table $table): Table
    {
        // NOTE: the gate deliberately lives in the data-producing
        // closure below (and in generateSnapshot()), not in this
        // builder body — `table()` is invoked during schema
        // construction, before any tenant context exists, and produces
        // no rows itself.
        return $table
            ->records(function (): Collection {
                $matter = $this->gatedMatter();

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceSnapshot::query()
                    ->where('matter_id', $matter->id)
                    ->with('generatedBy')
                    ->orderByDesc('report_version')
                    ->get()
                    ->map(fn (FinancialEvidenceSnapshot $s): array => [
                        'id' => $s->id,
                        'report_version' => $s->report_version,
                        'source_product' => $s->source_product,
                        'date_range' => trim(($s->date_range_start?->toDateString() ?? '—').' – '.($s->date_range_end?->toDateString() ?? '—')),
                        'generated_by' => $s->generatedBy?->user?->name ?? 'Unknown',
                        'created_at' => $s->created_at?->toDayDateTimeString(),
                        'checksum' => $s->checksum !== null ? substr($s->checksum, 0, 12).'…' : 'None',
                    ]));
            })
            ->columns([
                TextColumn::make('report_version')->label('Version'),
                TextColumn::make('source_product')->label('Product'),
                TextColumn::make('date_range')->label('Date range'),
                TextColumn::make('generated_by')->label('Generated by'),
                TextColumn::make('created_at')->label('Generated at'),
                TextColumn::make('checksum')->label('Checksum'),
            ])
            ->emptyStateHeading('No snapshots yet')
            ->emptyStateDescription('A snapshot is the only source Report Export may read from — generate one before exporting.')
            ->paginated(false);
    }

    public function render()
    {
        $this->gatedMatter();

        return view('livewire.financial-evidence.snapshots-panel');
    }
}
