<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PlaidItemResource\RelationManagers;

use App\Integrations\Enums\FinancialAccountClassification;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialAccountReclassificationService;
use App\Models\FinancialEvidenceBankAccount;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * AccountsRelationManager — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2/§5). Connected
 * accounts for a Plaid Item, including the account classification
 * column/action. Ordinary reclassification writes directly
 * (`canRequest()`-gated); a sensitive transition instead opens a
 * `request()` call, routed into the two-person approval queue
 * (`PlaidReclassificationApprovalsPage`) — never a direct write for a
 * sensitive transition, even from this page.
 */
class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    protected static ?string $title = 'Accounts';

    public function getRelationship(): Relation|Builder
    {
        /** @var FirmIntegration $owner */
        $owner = $this->getOwnerRecord();

        return new HasMany(
            FinancialEvidenceBankAccount::query()->where('firm_integration_id', $owner->id),
            $owner,
            'firm_integration_id',
            'id',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_name')->label('Account')->placeholder('Untitled account'),
                TextColumn::make('mask')->label('Mask')->placeholder('—'),
                TextColumn::make('classification')
                    ->label('Classification')
                    ->badge()
                    ->placeholder('Unclassified')
                    ->formatStateUsing(fn (?string $state): ?string => $state !== null ? FinancialAccountClassification::from($state)->label() : null)
                    ->color(fn (?string $state): string => $state !== null ? FinancialAccountClassification::from($state)->badgeColor() : 'gray'),
            ])
            ->recordActions([
                Action::make('reclassify')
                    ->label('Reclassify')
                    ->schema([
                        Select::make('target')
                            ->label('New classification')
                            ->options(collect(FinancialAccountClassification::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                            ->required(),
                        Textarea::make('reason')->label('Reason')->required(),
                    ])
                    ->action(function (array $data, FinancialEvidenceBankAccount $record): void {
                        $this->handleReclassify($record, $data);
                    }),
            ])
            ->emptyStateHeading('No accounts materialized yet for this connection');
    }

    private function handleReclassify(FinancialEvidenceBankAccount $account, array $data): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            Notification::make()->title('No active firm membership.')->danger()->send();

            return;
        }

        $target = FinancialAccountClassification::from($data['target']);
        $current = $account->classification !== null ? FinancialAccountClassification::from($account->classification) : null;

        try {
            if (FinancialAccountClassification::isSensitiveTransition($current, $target)) {
                app(FinancialAccountReclassificationService::class)->request(
                    $firmUser->firm,
                    $account,
                    $firmUser,
                    $target,
                    $data['reason'],
                );

                Notification::make()
                    ->title('Sensitive reclassification requested')
                    ->body('This transition requires two-person approval — see Pending Trust-Account Approvals.')
                    ->success()
                    ->send();

                return;
            }

            app(FinancialAccountReclassificationService::class)->reclassifyDirectly(
                $firmUser->firm,
                $account,
                $firmUser,
                $target,
            );

            Notification::make()->title('Account reclassified')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Could not reclassify')->body($e->getMessage())->danger()->send();
        }
    }
}
