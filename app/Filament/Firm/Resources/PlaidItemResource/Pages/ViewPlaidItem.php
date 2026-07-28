<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PlaidItemResource\Pages;

use App\Filament\Firm\Resources\PlaidItemResource;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ViewPlaidItem — FirmsVault Live Integrations, Checkpoint 4 ("Plaid
 * financial evidence add-on"; checkpoint4-design-workspace-and-admin-ui.md
 * §2). Reuses `ViewFirmIntegration`'s own Infolist Section pattern for
 * Item detail (status, `external_account_id` = item_id,
 * `external_tenant_id` = institution_id). Reconnect action visible only
 * when `ConnectionStatus::ReauthorizationRequired`; calls
 * `initiateLinkTokenConnection()` (the real, currently-implemented
 * Link-token initiation flow — `initiateLinkTokenUpdateMode()` is not
 * yet implemented anywhere in the live `ProviderConnectionService`,
 * a genuine gap in the provider-core track's own current state, not
 * this track's to fill; disclosed as a judgment call in this track's
 * final report). Product/package configuration and Consent status are
 * both surfaced as read-only Infolist sections on this same page rather
 * than a separate RelationManager, since neither needs its own
 * paginated table.
 */
class ViewPlaidItem extends ViewRecord
{
    protected static string $resource = PlaidItemResource::class;

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        /** @var FirmIntegration $record */
        $record = parent::resolveRecord($key);

        // Re-checked server-side — never trusts the list page's own
        // provider-filtered getEloquentQuery() as the real boundary.
        if ($record->integrationProvider?->code !== ProviderKey::Plaid->value) {
            abort(404);
        }

        return $record;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('display_label')->label('Name')->placeholder('Untitled connection'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                            ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                                'active' => 'success',
                                'pending' => 'gray',
                                'scope_insufficient', 'reauthorization_required' => 'warning',
                                'error' => 'danger',
                                'disconnected' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('external_account_id')->label('Plaid item_id')->placeholder('—'),
                        TextEntry::make('external_tenant_id')->label('Institution id')->placeholder('—'),
                        TextEntry::make('connected_at')->dateTime()->placeholder('—'),
                        TextEntry::make('requested_capabilities_json')->label('Requested products')->listWithLineBreaks()->placeholder('—'),
                    ]),
                Section::make('Consent status')
                    ->description('Read-only from the firm side — firm staff cannot grant consent on a client\'s behalf.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('consent_granted_at')
                            ->label('Consented at')
                            ->state(fn (FirmIntegration $record): ?string => $this->latestConsent($record)?->granted_at?->toDayDateTimeString())
                            ->placeholder('Not yet consented'),
                        TextEntry::make('authorized_date_range')
                            ->label('Authorized date range')
                            ->state(fn (FirmIntegration $record): string => $this->authorizedDateRangeLabel($record)),
                    ]),
            ]);
    }

    private function latestConsent(FirmIntegration $record): ?FinancialEvidenceClientConsent
    {
        return (new TenantContextService)->runWithFirmContext($record->firm_id, fn () => FinancialEvidenceClientConsent::query()
            ->where('firm_integration_id', $record->id)
            ->latest('id')
            ->first());
    }

    private function authorizedDateRangeLabel(FirmIntegration $record): string
    {
        $authorization = (new TenantContextService)->runWithFirmContext($record->firm_id, fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('firm_integration_id', $record->id)
            ->whereNull('superseded_at')
            ->latest('id')
            ->first());

        if ($authorization === null) {
            return 'No active authorization';
        }

        return ($authorization->authorized_date_range_start?->toDateString() ?? 'No lower bound')
            .' – '
            .($authorization->authorized_date_range_end?->toDateString() ?? 'No upper bound');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->reconnectAction(),
            $this->disconnectAction(),
        ];
    }

    private function reconnectAction(): Action
    {
        return Action::make('reconnect')
            ->label('Reconnect (Link)')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(function (): bool {
                $record = $this->getRecord();
                $status = is_object($record->status) ? $record->status->value : $record->status;

                return $status === 'reauthorization_required';
            })
            ->action(function (): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('No active firm membership.')->danger()->send();

                    return;
                }

                try {
                    $connection = FirmIntegration::query()
                        ->where('id', $this->getRecord()->id)
                        ->where('firm_id', $firmUser->firm_id)
                        ->firstOrFail();

                    $result = app(ProviderConnectionService::class)->initiateLinkTokenConnection($connection, $firmUser->id);

                    Notification::make()
                        ->title('Reconnect Link session started')
                        ->body('Direct the client to the Client Portal to complete reconnection (link_token expires '.$result->expiration.').')
                        ->success()
                        ->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not start reconnect')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('No active firm membership.')->danger()->send();

                    return;
                }

                try {
                    $connection = FirmIntegration::query()
                        ->where('id', $this->getRecord()->id)
                        ->where('firm_id', $firmUser->firm_id)
                        ->firstOrFail();

                    app(ProviderConnectionService::class)->disconnect($connection, currentUserId: $firmUser->id);

                    Notification::make()->title('Connection disconnected')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not disconnect')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
