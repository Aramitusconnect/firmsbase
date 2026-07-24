<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Pages;

use App\Filament\Firm\Resources\FirmIntegrationResource;
use App\Integrations\Data\FirmIntegrationCredentialSummary;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\ProviderConnectionService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ViewFirmIntegration — Checkpoint 10 (frozen-design-post-security-
 * review.md §11, §12). Connection health is an inline Infolist section
 * here (no separate Widget class, per §11.2's reasoning). Disconnect,
 * rename, and the two webhook-routing-toggle actions are inlined as
 * `Action::make(...)` closures directly on this page, per the frozen
 * design's explicit allowance ("MAY be inlined... implementer
 * discretion").
 *
 * Secret-safety discipline (frozen design §9, and this checkpoint's own
 * hard constraint): NO closure on this page ever reads the raw
 * `firm_integrations.webhook_routing_token` attribute (a HIDDEN-ONLY
 * column) — not even for a null-check to decide which webhook-routing
 * action to show. Both enableWebhookRouting()/disableWebhookRouting()
 * are individually idempotent (each method's own docblock), so BOTH
 * actions are always offered rather than branching UI state on that
 * column at all. The one legitimate display of the raw routing token
 * (10D §1.1: "intentionally meant to be shown to the firm admin once")
 * happens ONLY via enableWebhookRouting()'s own plaintext RETURN VALUE,
 * surfaced once in a Notification body immediately after the call —
 * never re-read from the model afterward.
 *
 * All credential-derived display goes through
 * IntegrationCredentialService::getMaskedMetadata() /
 * FirmIntegrationCredentialSummary exclusively (frozen design §9 item
 * 1) — no closure on this page ever binds an IntegrationCredential
 * instance directly to any Field/Column/Entry.
 * `IntegrationConflict.local_value`/`external_value` are never rendered
 * anywhere on this page (see ConflictsRelationManager's identical
 * decision).
 *
 * TOCTOU discipline (frozen design §10): every action below re-fetches
 * the connection fresh by primary key INSIDE its own action() closure
 * — never reuses `$this->getRecord()`'s mount()-time value for any
 * security-relevant decision — and re-runs every authorization/
 * lifecycle check unconditionally via the underlying service call,
 * which itself performs the real, synchronous boundary.
 *
 * PRODUCTION BUG FIX (post-Checkpoint-10 hardening): Filament's shared
 * `livewire/update` AJAX endpoint — which every action() closure below
 * actually executes through — carries only Filament's own fixed,
 * package-boot-time `Livewire::addPersistentMiddleware()` list (see
 * vendor/filament/filament/src/FilamentServiceProvider.php), never this
 * app's `EstablishFirmTenantContext`/`ApplyTenantDatabaseContext`
 * (wired only into `FirmPanelProvider`'s `authMiddleware`, which governs
 * page-LOAD routes only). So each closure's own fresh re-fetch below
 * previously ran with NO `app.current_firm_id` PostgreSQL session
 * setting at all, and FORCE ROW LEVEL SECURITY on `firm_integrations`
 * made it find zero rows every time, even for a legitimate, authorized
 * user (`ModelNotFoundException`). Each closure below now resolves the
 * ACTING user's own firm via `Auth::user()->activeFirmUser()` — the
 * same session-only-resolvable mechanism `isConfigurable()`/
 * `disconnectAction()`'s `visible()` closure already use, safe because
 * `activeFirmUser()` establishes only the narrow `app.current_user_id`
 * self-lookup setting (see `User::activeFirmUser()`), never
 * `app.current_firm_id` — and wraps the ENTIRE remainder of the closure
 * (fresh re-fetch + the underlying `ProviderConnectionService` call,
 * which itself also calls `TenantContextService::runWithFirmContext()`
 * — confirmed safe/re-entrant, see that method's own docblock) inside
 * `TenantContextService::runWithFirmContext($firmId, ...)`. This changes
 * ONLY where tenant context comes from; every authorization/lifecycle
 * check and TOCTOU re-fetch below is unchanged.
 */
class ViewFirmIntegration extends ViewRecord
{
    protected static string $resource = FirmIntegrationResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connection')
                ->columns(2)
                ->schema([
                    TextEntry::make('display_label')->label('Name')->placeholder('Untitled connection'),
                    TextEntry::make('integrationProvider.display_name')->label('Provider'),
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
                    TextEntry::make('external_account_id')->label('External account')->placeholder('—'),
                    TextEntry::make('connected_at')->dateTime()->placeholder('—'),
                    TextEntry::make('disconnected_at')->dateTime()->placeholder('—'),
                    TextEntry::make('scopes_granted_json')->label('Granted scopes')->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('error_reason')->label('Error')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Health')
                ->columns(2)
                ->schema([
                    TextEntry::make('health_summary_state')
                        ->label('Status')
                        ->state(fn (FirmIntegration $record) => app(HealthStateService::class)->summaryFor($record)->summaryState->value)
                        ->badge(),
                    TextEntry::make('health_consecutive_failures')
                        ->label('Consecutive failures')
                        ->state(fn (FirmIntegration $record) => app(HealthStateService::class)->summaryFor($record)->consecutiveFailures),
                    TextEntry::make('health_last_success_at')
                        ->label('Last success')
                        ->state(fn (FirmIntegration $record) => app(HealthStateService::class)->summaryFor($record)->lastSuccessAt)
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('health_last_failure_at')
                        ->label('Last failure')
                        ->state(fn (FirmIntegration $record) => app(HealthStateService::class)->summaryFor($record)->lastFailureAt)
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('health_diagnostic')
                        ->label('Diagnostic')
                        ->state(fn (FirmIntegration $record) => app(HealthStateService::class)->summaryFor($record)->sanitizedDiagnosticSummary)
                        ->placeholder('No diagnostic recorded.')
                        ->columnSpanFull(),
                ]),

            Section::make('Credentials')
                ->schema([
                    TextEntry::make('credential_summary')
                        ->hiddenLabel()
                        ->state(function (FirmIntegration $record) {
                            $service = app(IntegrationCredentialService::class);

                            $summaries = IntegrationCredential::query()
                                ->where('firm_integration_id', $record->id)
                                ->orderByDesc('created_at')
                                ->get()
                                ->map(fn (IntegrationCredential $credential) => FirmIntegrationCredentialSummary::fromMaskedMetadata(
                                    $service->getMaskedMetadata($credential)
                                ));

                            if ($summaries->isEmpty()) {
                                return ['No credentials on record for this connection.'];
                            }

                            return $summaries->map(function (FirmIntegrationCredentialSummary $summary): string {
                                $line = sprintf(
                                    '%s — %s',
                                    str($summary->credentialType->value)->replace('_', ' ')->headline(),
                                    str($summary->status->value)->headline(),
                                );

                                if ($summary->expiresAt !== null) {
                                    $line .= " (expires {$summary->expiresAt->toDayDateTimeString()})";
                                }

                                return $line;
                            })->all();
                        })
                        ->listWithLineBreaks(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->renameAction(),
            $this->enableWebhookRoutingAction(),
            $this->disableWebhookRoutingAction(),
            $this->disconnectAction(),
        ];
    }

    private function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                TextInput::make('display_label')->label('Name')->required()->maxLength(255),
            ])
            ->fillForm(fn (FirmIntegration $record): array => ['display_label' => (string) $record->display_label])
            ->visible(fn (FirmIntegration $record): bool => $this->isConfigurable($record))
            ->action(function (array $data, FirmIntegration $record, ProviderConnectionService $connectionService): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('You do not have access to this connection.')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $data, $connectionService): void {
                    $fresh = FirmIntegration::query()->where('id', $record->id)->firstOrFail();

                    try {
                        $connectionService->renameConnection($fresh, (int) Auth::id(), (string) $data['display_label']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Could not rename connection')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Connection renamed')->success()->send();
                });
            });
    }

    private function enableWebhookRoutingAction(): Action
    {
        return Action::make('enableWebhookRouting')
            ->label('Enable / Regenerate Webhook Routing')
            ->icon(Heroicon::OutlinedLink)
            ->requiresConfirmation()
            ->modalDescription('This generates a new routing token and invalidates any previous one. The token is shown only once — copy it into your provider webhook configuration immediately.')
            ->visible(fn (FirmIntegration $record): bool => $this->isConfigurable($record))
            ->action(function (FirmIntegration $record, ProviderConnectionService $connectionService): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('You do not have access to this connection.')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $connectionService): void {
                    $fresh = FirmIntegration::query()->where('id', $record->id)->firstOrFail();

                    try {
                        $rawToken = $connectionService->enableWebhookRouting($fresh, (int) Auth::id());
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Could not enable webhook routing')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    // The ONE legitimate display of this value — from the
                    // method's own plaintext return, never re-read from the
                    // model. Never logged, never persisted beyond this
                    // single notification.
                    Notification::make()
                        ->title('Webhook routing enabled')
                        ->body("Routing token (copy now — shown only once):\n{$rawToken}")
                        ->success()
                        ->persistent()
                        ->send();
                });
            });
    }

    private function disableWebhookRoutingAction(): Action
    {
        return Action::make('disableWebhookRouting')
            ->label('Disable Webhook Routing')
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (FirmIntegration $record): bool => $this->isConfigurable($record))
            ->action(function (FirmIntegration $record, ProviderConnectionService $connectionService): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('You do not have access to this connection.')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $connectionService): void {
                    $fresh = FirmIntegration::query()->where('id', $record->id)->firstOrFail();

                    try {
                        $connectionService->disableWebhookRouting($fresh, (int) Auth::id());
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Could not disable webhook routing')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Webhook routing disabled')->success()->send();
                });
            });
    }

    private function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This revokes credentials and disables webhook routing for this connection. It cannot be undone from here — reconnecting starts a new connection.')
            ->visible(function (FirmIntegration $record): bool {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                    return false;
                }

                return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
                    && app(IntegrationAccessPolicyService::class)->canDisconnect($firmUser->role);
            })
            ->action(function (FirmIntegration $record, ProviderConnectionService $connectionService): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('You do not have access to this connection.')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $connectionService): void {
                    $fresh = FirmIntegration::query()->where('id', $record->id)->firstOrFail();

                    try {
                        $connectionService->disconnect($fresh, (int) Auth::id());
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Could not disconnect')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Connection disconnected')->success()->send();
                });
            });
    }

    /**
     * UX-only visibility helper shared by rename/webhook-routing-toggle
     * actions — mirrors assertCanConfigure()'s ceiling. Never the
     * security boundary; each action's own service call re-asserts this
     * for real.
     */
    private function isConfigurable(FirmIntegration $record): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canConfigure($firmUser->role);
    }
}
