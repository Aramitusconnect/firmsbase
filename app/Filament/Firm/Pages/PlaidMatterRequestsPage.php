<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Enums\ResourceType;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Matter;
use App\Services\PlaidEntitlementPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
use RuntimeException;

/**
 * PlaidMatterRequestsPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2/§4.1). Lists
 * `financial_evidence_matter_requests` across this firm's matters — the
 * firm-side view of "who's been asked to connect and what's their
 * status," and the counterpart action that CREATES a request the
 * Client Portal's `PlaidRequestReviewPage` later reads.
 */
class PlaidMatterRequestsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Matter Financial Requests';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $title = 'Matter Financial-Evidence Requests';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(FinancialIntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->newRequestAction()];
    }

    private function newRequestAction(): Action
    {
        return Action::make('newRequest')
            ->label('Request Financial Evidence')
            ->schema([
                Select::make('matter_id')
                    ->label('Matter')
                    ->options(function (): array {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null) {
                            return [];
                        }

                        return Matter::query()->where('firm_id', $firmUser->firm_id)->orderBy('id', 'desc')->limit(200)->pluck('id', 'id')->all();
                    })
                    ->required()
                    ->searchable(),
                CheckboxList::make('requested_products')
                    ->label('Requested products')
                    ->options([
                        ResourceType::BankAccount->value => 'Account details',
                        ResourceType::Transaction->value => 'Transaction history',
                        ResourceType::Income->value => 'Income',
                        ResourceType::Liability->value => 'Liabilities',
                        ResourceType::Investment->value => 'Investments',
                        ResourceType::Statement->value => 'Statements',
                        ResourceType::Identity->value => 'Identity',
                    ])
                    ->required(),
                Textarea::make('purpose')->label('Purpose')->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    return;
                }

                try {
                    app(FinancialIntegrationAccessPolicyService::class)->assertCanRequest($firmUser);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Request denied')->body($exception->getMessage())->danger()->send();

                    return;
                }

                (new TenantContextService)->runWithFirmContext($firmUser->firm_id, function () use ($firmUser, $data) {
                    // Server-side re-validation: the Select's options are
                    // firm-scoped for DISPLAY only — Filament does not
                    // enforce that the submitted matter_id still belongs to
                    // the actor's own firm, so a tampered/forged submission
                    // must be rejected here rather than trusted as-is.
                    $matter = Matter::query()
                        ->where('firm_id', $firmUser->firm_id)
                        ->where('id', (int) $data['matter_id'])
                        ->firstOrFail();

                    FinancialEvidenceMatterRequest::query()->create([
                        'firm_id' => $firmUser->firm_id,
                        'matter_id' => $matter->id,
                        'requested_by_firm_user_id' => $firmUser->id,
                        'purpose' => $data['purpose'],
                        'requested_products_json' => array_values($data['requested_products']),
                        'status' => 'pending',
                        'requested_at' => now(),
                    ]);
                });

                Notification::make()->title('Request created')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || ! app(FinancialIntegrationAccessPolicyService::class)->canView($firmUser->role)) {
                    return collect();
                }

                return FinancialEvidenceMatterRequest::query()
                    ->where('firm_id', $firmUser->firm_id)
                    ->orderByDesc('requested_at')
                    ->get();
            })
            ->columns([
                TextColumn::make('matter_id')->label('Matter'),
                TextColumn::make('purpose')->limit(60),
                TextColumn::make('status')->badge(),
                TextColumn::make('requested_at')->label('Requested')->dateTime(),
            ])
            ->emptyStateHeading('No financial-evidence requests yet')
            ->paginated([25, 50]);
    }
}
