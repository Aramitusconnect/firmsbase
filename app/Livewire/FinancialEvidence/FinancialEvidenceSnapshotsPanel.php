<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Integrations\Services\FinancialEvidenceMatterScopeService;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
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
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * FinancialEvidenceSnapshotsPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.9). Immutable, one
 * row per snapshot-creation event. "Create Snapshot" opens a Filament
 * wizard action (mirrors `ConnectProviderAction`'s `Action::steps()`
 * shape) with an explicit review step before the irreversible
 * "Generate" submit.
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
    }

    public function content(Schema $schema): Schema
    {
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
                                $bankAccountIds = app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($this->matter());

                                return (new TenantContextService)->runWithFirmContext($this->matter()->firm_id, fn () => FinancialEvidenceBankAccount::query()
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
        $matter = $this->matter();
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        app(FinancialIntegrationAccessPolicyService::class)->assertCanView($firmUser);

        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $firmUser, $data) {
            $bankAccountIds = array_map('intval', $data['bank_account_ids'] ?? []);

            $authorization = FinancialEvidenceMatterAuthorization::query()
                ->where('matter_id', $matter->id)
                ->whereNull('superseded_at')
                ->latest('id')
                ->first();

            $consent = $authorization?->consent_id !== null
                ? FinancialEvidenceClientConsent::query()->find($authorization->consent_id)
                : null;

            $bankAccounts = FinancialEvidenceBankAccount::query()->whereIn('id', $bankAccountIds)->get();
            $firmIntegrationIds = $bankAccounts->pluck('firm_integration_id')->unique();

            $transactionRefs = FinancialEvidenceTransaction::query()
                ->whereIn('bank_account_id', $bankAccountIds)
                ->pluck('id')
                ->map(fn (int $id): array => ['type' => 'financial_evidence_transactions', 'id' => $id])
                ->all();

            $latestRetrievedAt = FinancialEvidenceTransaction::query()
                ->whereIn('bank_account_id', $bankAccountIds)
                ->max('provider_retrieved_at');

            $lastReportVersion = (int) FinancialEvidenceSnapshot::query()
                ->where('matter_id', $matter->id)
                ->max('report_version');

            $checksumSource = $transactionRefs === [] ? null : hash('sha256', json_encode($transactionRefs));

            FinancialEvidenceSnapshot::query()->create([
                'firm_id' => $matter->firm_id,
                'matter_id' => $matter->id,
                'generated_by_firm_user_id' => $firmUser->id,
                'consent_id' => $consent?->id,
                'authorized_source_json' => ['firm_integration_ids' => $firmIntegrationIds->values()->all()],
                'authorized_account_ids_json' => $bankAccountIds,
                'date_range_start' => $authorization?->authorized_date_range_start,
                'date_range_end' => $authorization?->authorized_date_range_end,
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
        return $table
            ->records(function (): Collection {
                $matter = $this->matter();

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
        return view('livewire.financial-evidence.snapshots-panel');
    }
}
