<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Integrations\Services\FinancialEvidenceMatterScopeService;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceTransaction;
use App\Models\FinancialEvidenceTransactionReview;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Component;
use RuntimeException;

/**
 * FinancialEvidenceTransactionSearchPanel — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.6). Bounded date-
 * range/amount/account/category filters, text search, reviewed/
 * unreviewed, flagged/unflagged. Backed by the immutable
 * `financial_evidence_transactions` row joined to the mutable,
 * append-only `financial_evidence_transaction_reviews` table
 * (provenance split: fact vs. review).
 */
class FinancialEvidenceTransactionSearchPanel extends Component implements HasActions, HasSchemas, HasTable
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
        $this->gatedMatter();

        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        // NOTE: the gate deliberately lives in the data-producing
        // closures below (and in recordReview()), not in this builder
        // body — `table()` is invoked during schema construction,
        // before any tenant context exists, and produces no rows
        // itself. Every path that actually READS or WRITES a row
        // re-runs both gates.
        return $table
            ->records(function (?array $filters, ?string $search): Collection {
                // Transaction rows are financial-tier data: the tier is
                // re-asserted here (not only in recordReview()), so a
                // role below the tier can never read them.
                $matter = $this->gatedMatter();
                $bankAccountIds = app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($matter);

                if ($bankAccountIds === []) {
                    return collect();
                }

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($bankAccountIds, $filters, $search) {
                    $query = FinancialEvidenceTransaction::query()->whereIn('bank_account_id', $bankAccountIds);

                    if (! empty($filters['date_from'] ?? null)) {
                        $query->whereDate('transaction_date', '>=', $filters['date_from']);
                    }

                    if (! empty($filters['date_until'] ?? null)) {
                        $query->whereDate('transaction_date', '<=', $filters['date_until']);
                    }

                    if (($filters['amount_min'] ?? null) !== null && $filters['amount_min'] !== '') {
                        $query->where('amount_cents', '>=', (int) round(((float) $filters['amount_min']) * 100));
                    }

                    if (($filters['amount_max'] ?? null) !== null && $filters['amount_max'] !== '') {
                        $query->where('amount_cents', '<=', (int) round(((float) $filters['amount_max']) * 100));
                    }

                    if (! empty($filters['bank_account_id'] ?? null)) {
                        $query->where('bank_account_id', $filters['bank_account_id']);
                    }

                    if ($search) {
                        $query->where('merchant_name', 'ilike', "%{$search}%");
                    }

                    $transactions = $query->orderByDesc('transaction_date')->limit(500)->get();

                    $latestReviews = FinancialEvidenceTransactionReview::query()
                        ->whereIn('transaction_id', $transactions->pluck('id'))
                        ->orderByDesc('id')
                        ->get()
                        ->groupBy('transaction_id')
                        ->map(fn ($reviews) => $reviews->first());

                    return $transactions
                        ->map(function (FinancialEvidenceTransaction $t) use ($latestReviews): array {
                            /** @var FinancialEvidenceTransactionReview|null $review */
                            $review = $latestReviews->get($t->id);

                            return [
                                'id' => $t->id,
                                'transaction_date' => $t->transaction_date?->toDateString(),
                                'merchant_name' => $t->merchant_name ?? '—',
                                'amount' => number_format($t->amount_cents / 100, 2),
                                'pending' => $t->pending,
                                'reviewed' => $review !== null,
                                'flagged' => (bool) $review?->flagged,
                                'classification' => $review?->classification,
                            ];
                        })
                        ->when(($filters['reviewed'] ?? null) === '1', fn ($c) => $c->where('reviewed', true))
                        ->when(($filters['reviewed'] ?? null) === '0', fn ($c) => $c->where('reviewed', false))
                        ->when(($filters['flagged'] ?? null) === '1', fn ($c) => $c->where('flagged', true))
                        ->when(($filters['flagged'] ?? null) === '0', fn ($c) => $c->where('flagged', false))
                        ->values();
                });
            })
            ->columns([
                TextColumn::make('transaction_date')->label('Date'),
                TextColumn::make('merchant_name')->label('Merchant')->searchable(),
                TextColumn::make('amount')->label('Amount')->alignEnd(),
                TextColumn::make('pending')->label('Pending')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextColumn::make('reviewed')->label('Reviewed')->formatStateUsing(fn (bool $state): string => $state ? 'Reviewed' : 'Unreviewed')->badge()->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('flagged')->label('Flagged')->formatStateUsing(fn (bool $state): string => $state ? 'Flagged' : '—')->badge()->color(fn (bool $state) => $state ? 'warning' : 'gray'),
                TextColumn::make('classification')->label('Classification')->placeholder('—'),
                TextColumn::make('provenance_fact')
                    ->label('Provenance')
                    ->badge()
                    ->state(FinancialEvidenceProvenance::ProviderSuppliedFact->label())
                    ->color(FinancialEvidenceProvenance::ProviderSuppliedFact->badgeColor()),
            ])
            ->filters([
                Filter::make('date_from')->schema([DatePicker::make('date_from')->label('From date')])->columnSpan(1),
                Filter::make('date_until')->schema([DatePicker::make('date_until')->label('To date')])->columnSpan(1),
                Filter::make('amount_min')->schema([TextInput::make('amount_min')->label('Min amount ($)')->numeric()]),
                Filter::make('amount_max')->schema([TextInput::make('amount_max')->label('Max amount ($)')->numeric()]),
                SelectFilter::make('bank_account_id')
                    ->label('Account')
                    ->options(function (): array {
                        $matter = $this->gatedMatter();
                        $bankAccountIds = app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($matter);

                        return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceBankAccount::query()
                            ->whereIn('id', $bankAccountIds)
                            ->get()
                            ->mapWithKeys(fn (FinancialEvidenceBankAccount $a) => [$a->id => $a->account_name ?? "Account #{$a->id}"])
                            ->all());
                    }),
                SelectFilter::make('reviewed')->label('Review status')->options(['1' => 'Reviewed', '0' => 'Unreviewed']),
                SelectFilter::make('flagged')->label('Flag status')->options(['1' => 'Flagged', '0' => 'Unflagged']),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->schema([
                        Toggle::make('flagged')->label('Flag this transaction'),
                        TextInput::make('flag_reason')->label('Flag reason')->visible(fn ($get) => (bool) $get('flagged')),
                        TextInput::make('classification')->label('Classification'),
                    ])
                    ->action(function (array $data, array $record): void {
                        $this->recordReview((int) $record['id'], $data);
                    }),
            ])
            ->searchable()
            ->emptyStateHeading('No transactions match this search')
            ->paginated([25, 50, 100]);
    }

    /**
     * H2 remediation. `financial_evidence_transactions` carries no
     * `matter_id` of its own — a transaction belongs to this matter
     * only transitively, through a bank account reachable from one of
     * the matter's currently-authorized connections. Previously the
     * submitted `$transactionId` was written straight into a review row
     * after validating only the CURRENT matter, so an attorney
     * authorized for Matter A could tamper with the id and record a
     * review against Matter B's (or another firm's) transaction.
     *
     * Now the id is resolved through a query constrained by firm_id AND
     * the matter's own authorized bank-account allowlist (re-derived
     * server-side here, never trusted from the rendered page) — never
     * `::find($id)`.
     */
    private function recordReview(int $transactionId, array $data): void
    {
        [$matter, $firmUser] = $this->gatedFinancialEvidenceContext();

        (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $transactionId, $data, $firmUser) {
            $bankAccountIds = app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($matter);

            if ($bankAccountIds === []) {
                throw new RuntimeException('This matter has no currently-authorized financial connection.');
            }

            $transaction = FinancialEvidenceTransaction::query()
                ->where('firm_id', $matter->firm_id)
                ->whereIn('bank_account_id', $bankAccountIds)
                ->where('id', $transactionId)
                ->firstOrFail();

            FinancialEvidenceTransactionReview::query()->create([
                'firm_id' => $matter->firm_id,
                'transaction_id' => $transaction->id,
                'reviewed_by_firm_user_id' => $firmUser->id,
                'reviewed_at' => now(),
                'flagged' => (bool) ($data['flagged'] ?? false),
                'flag_reason' => $data['flag_reason'] ?? null,
                'classification' => $data['classification'] ?? null,
            ]);
        });

        Notification::make()->title('Review recorded')->success()->send();
    }

    public function render()
    {
        $this->gatedMatter();

        return view('livewire.financial-evidence.transaction-search-panel');
    }
}
