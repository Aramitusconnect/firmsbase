<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\AuthorizeProviderOperationRetryAction;
use App\Filament\Actions\Platform\ConfirmProviderOperationSucceededAction;
use App\Filament\Actions\Platform\ResolveProviderOperationWithoutRetryAction;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformProviderOperationReconciliationPage — Checkpoint 8.2
 * (§A-reconciliation). The first production consumer of
 * `ProviderOperationAttemptService::resolveReconciliation()`, which
 * previously had ZERO callers anywhere in this codebase: a
 * `reconciliation_required` row was a one-way trapdoor with no operator
 * exit, in either the durable gate table or the `webhook_bootstrap_state`
 * column it also drives.
 *
 * Gate: `canAccessIntegrationOversight()` for viewing (the same gate
 * every other Integration Operations Center page uses) and
 * `canManageIntegrationConnections()` + the blanket `canMutate()` for
 * every resolution action — see those three actions' own classes.
 *
 * SAFE BY CONSTRUCTION, NOT BY CONVENTION. `provider_operation_attempts`
 * is FK-free, RLS-EXEMPT and unencrypted by design (see that table's own
 * create-migration docblock and
 * `tests/Feature/Integrations/DurableOperationMetadataRedactionTest.php`)
 * — every column this table can ever carry is ALREADY restricted to
 * short machine categories and the two non-secret fields each caller's
 * own `redactedResultMetadata` closure chooses to keep (an external id
 * and a version/expiry token, both already stored unencrypted elsewhere
 * for exactly this purpose). This page therefore displays every column
 * on the row directly — there is no raw provider payload, token, or
 * cursor that could ever land here to redact.
 */
class PlatformProviderOperationReconciliationPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Provider Reconciliation';

    protected static ?string $title = 'Provider Operation Reconciliation';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
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
            ->query(function (): Builder {
                // Re-checked here too, never trusting canAccess()/mount
                // time alone — matching every other Integration
                // Operations Center page's own established discipline.
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin
                    || ! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
                    return ProviderOperationAttempt::query()->whereRaw('1 = 0');
                }

                return app(ProviderOperationAttemptService::class)->queryRequiringReconciliation();
            })
            ->filters([
                SelectFilter::make('provider_key')
                    ->label('Provider')
                    ->options(fn (): array => app(ProviderOperationAttemptService::class)
                        ->queryRequiringReconciliation()
                        ->distinct()
                        ->pluck('provider_key', 'provider_key')
                        ->all()),
                SelectFilter::make('operation_type')
                    ->label('Operation Type')
                    ->options(fn (): array => app(ProviderOperationAttemptService::class)
                        ->queryRequiringReconciliation()
                        ->distinct()
                        ->pluck('operation_type', 'operation_type')
                        ->all()),
                Filter::make('firm_id')
                    ->schema([
                        TextInput::make('firm_id')
                            ->label('Firm ID')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['firm_id'] ?? null,
                        fn (Builder $q, $firmId) => $q->where('firm_id', (int) $firmId),
                    )),
                Filter::make('older_than')
                    ->label('Age')
                    ->schema([
                        FormSelect::make('hours')
                            ->label('Older than')
                            ->options([
                                '1' => '1 hour',
                                '24' => '1 day',
                                '168' => '1 week',
                            ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['hours'] ?? null,
                        fn (Builder $q, $hours) => $q->where('updated_at', '<=', Carbon::now()->subHours((int) $hours)),
                    )),
            ])
            ->columns([
                TextColumn::make('provider_key')->label('Provider')->badge()->sortable(),
                TextColumn::make('operation_type')->label('Operation')->sortable()->searchable(),
                TextColumn::make('firm_id')->label('Firm ID')->sortable(),
                TextColumn::make('firm_integration_id')->label('Connection ID')->placeholder('—'),
                TextColumn::make('attempt_state')->label('State')->badge()->color('danger'),
                TextColumn::make('reconciliation_reason')->label('Reason')->placeholder('—')->wrap(),
                TextColumn::make('send_count')->label('Sends')->alignEnd(),
                TextColumn::make('total_send_count')->label('Total Sends')->alignEnd(),
                TextColumn::make('provider_started_at')->label('Provider Started')->since()->sinceTooltip()->placeholder('—'),
                TextColumn::make('provider_completed_at')->label('Provider Completed')->since()->sinceTooltip()->placeholder('—'),
                TextColumn::make('updated_at')->label('Last Updated')->since()->sinceTooltip()->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        TextEntry::make('logical_operation_key')->label('Logical Operation Key'),
                        TextEntry::make('provider_outcome')->label('Provider Outcome')->placeholder('—'),
                        TextEntry::make('redacted_result_metadata')->label('Redacted Result Metadata')->placeholder('—'),
                        TextEntry::make('local_processing_state')->label('Local Processing State')->placeholder('—'),
                        TextEntry::make('provider_request_reference')->label('Provider Request Reference')->placeholder('—'),
                    ]),
                ConfirmProviderOperationSucceededAction::make(),
                AuthorizeProviderOperationRetryAction::make(),
                ResolveProviderOperationWithoutRetryAction::make(),
            ])
            ->emptyStateHeading('Nothing needs reconciliation right now')
            ->emptyStateDescription('Every outbound provider operation has either completed or is safely retryable on its own.')
            ->defaultSort('updated_at')
            ->paginated([25, 50, 100]);
    }
}
