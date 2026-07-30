<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Pages;

use App\Filament\Firm\Resources\FirmIntegrationResource;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\FirmIntegrationCredentialSummary;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\WebhookBootstrapState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\ProviderConnectionService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
                    // FirmsVault Live Integrations, Checkpoint 2 addition
                    // (checkpoint2-combined-design.md §2 P-4/P-5, §4;
                    // checkpoint2-design-ui.md §3): same styling/placeholder
                    // convention as external_account_id immediately above.
                    // Populated only by Microsoft-365-specific code at
                    // OAuth-callback-finish time
                    // (ProviderConnectionService::finishCallback(), P-6d)
                    // from the token exchange's non-secret tid claim —
                    // never decrypted for display here.
                    TextEntry::make('external_tenant_id')->label('Tenant')->placeholder('—'),
                    TextEntry::make('connected_at')->dateTime()->placeholder('—'),
                    TextEntry::make('disconnected_at')->dateTime()->placeholder('—'),
                    TextEntry::make('scopes_granted_json')->label('Granted scopes')->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('error_reason')->label('Error')->placeholder('—')->columnSpanFull(),

                    // CHECKPOINT 8.2 addition (§A7b). The webhook bootstrap
                    // now runs AFTER the OAuth transaction commits, so a
                    // connection can legitimately be Active while real-time
                    // delivery is not yet working. Saying nothing here would
                    // leave the firm believing push updates are live when
                    // they are not — the status badge above reads "active"
                    // and would be the only signal.
                    //
                    // Rendered for EVERY state, including the healthy one,
                    // so its absence never has to be interpreted. The copy
                    // comes from WebhookBootstrapState::firmFacingSummary(),
                    // which always names both what is degraded and what
                    // still works.
                    TextEntry::make('webhook_bootstrap_state')
                        ->label('Real-time updates')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state instanceof WebhookBootstrapState
                            ? match ($state) {
                                WebhookBootstrapState::Complete => 'Active',
                                WebhookBootstrapState::NotRequired => 'Not used',
                                WebhookBootstrapState::Pending => 'Setting up',
                                WebhookBootstrapState::PendingRetry => 'Retrying',
                                WebhookBootstrapState::Failed => 'Not set up',
                                WebhookBootstrapState::ReconciliationRequired => 'Needs review',
                            }
                            : (string) $state)
                        ->color(fn ($state): string => match (true) {
                            ! $state instanceof WebhookBootstrapState => 'gray',
                            $state === WebhookBootstrapState::Complete => 'success',
                            $state === WebhookBootstrapState::NotRequired => 'gray',
                            $state->needsHumanAttention() => 'danger',
                            default => 'warning',
                        })
                        ->helperText(fn ($state): string => $state instanceof WebhookBootstrapState
                            ? $state->firmFacingSummary()
                            : '')
                        ->columnSpanFull(),
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
            $this->reconnectAction(),
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

    /**
     * FirmsVault Live Integrations, Checkpoint 2 addition
     * (checkpoint2-combined-design.md §4; checkpoint2-design-ui.md §4).
     * Closes the confirmed gap that a firm wanting to add a capability
     * (or recover from ScopeInsufficient/ReauthorizationRequired) had no
     * path except a full Disconnect + brand-new ConnectProviderAction
     * flow. Structurally identical to renameAction() above: fillForm()
     * prefills from the mount()-time record purely for display (never a
     * security-relevant read), the action() closure re-fetches fresh
     * inside runWithFirmContext() before calling the service, and
     * RuntimeException is caught and surfaced via Notification::danger()
     * exactly like every other action on this page.
     *
     * updateRequestedCapabilities() only updates the stored column — it
     * deliberately does not itself start a new OAuth round-trip (see its
     * own docblock). Redirecting here to the SAME existing
     * `integrations.oauth.initiate` route ConnectProviderAction already
     * uses is what actually re-requests the (now possibly broader) scope
     * bundle from the provider — that route/controller/service call is
     * already generic over connect-vs-reauthorize (initiateOAuthConnection()'s
     * own docblock), so no other change is needed for this to function.
     */
    private function reconnectAction(): Action
    {
        return Action::make('reconnect')
            ->label('Add Capabilities / Reconnect')
            ->icon(Heroicon::OutlinedArrowPath)
            ->schema([
                CheckboxList::make('capabilities')
                    ->label('What should this connection sync?')
                    ->helperText('Choose the data this firm needs. You will be redirected to reauthorize with the provider to apply any changes.')
                    ->options(function (FirmIntegration $record, ProviderRegistry $registry): array {
                        $key = $record->providerKey();

                        if ($key === null || ! $registry->has($key)) {
                            return [];
                        }

                        return collect($registry->metadataFor($key)->resourceTypes)
                            ->mapWithKeys(fn (string $type): array => [$type => self::capabilityLabel($type)])
                            ->all();
                    }),
            ])
            ->fillForm(fn (FirmIntegration $record): array => [
                'capabilities' => $record->requested_capabilities_json ?? [],
            ])
            ->visible(fn (FirmIntegration $record): bool => $this->isConfigurable($record)
                && in_array($record->status, [
                    ConnectionStatus::Active,
                    ConnectionStatus::ScopeInsufficient,
                    ConnectionStatus::ReauthorizationRequired,
                ], true))
            ->action(function (array $data, FirmIntegration $record, ProviderConnectionService $connectionService): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    Notification::make()->title('You do not have access to this connection.')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $data, $connectionService): void {
                    $fresh = FirmIntegration::query()->where('id', $record->id)->firstOrFail();

                    try {
                        $updated = $connectionService->updateRequestedCapabilities(
                            $fresh,
                            array_values((array) ($data['capabilities'] ?? [])),
                            (int) Auth::id(),
                        );
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Could not update requested capabilities')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $this->redirect(route('integrations.oauth.initiate', $updated));
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

    /**
     * FirmsVault Live Integrations, Checkpoint 2 addition
     * (checkpoint2-design-ui.md §1). Byte-for-byte the same mapping as
     * App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ConnectProviderAction's
     * own private capabilityLabel() — duplicated rather than shared,
     * matching this codebase's existing convention of each self-contained
     * Action/Page file owning its own small closures (see e.g. this
     * file's own class docblock on why every action here re-fetches
     * fresh rather than sharing state).
     */
    private static function capabilityLabel(string $resourceType): string
    {
        return match ($resourceType) {
            ResourceType::Message->value => 'Email',
            ResourceType::CalendarEvent->value => 'Calendar',
            ResourceType::Document->value => 'Files',
            ResourceType::Contact->value => 'Contacts',
            default => (string) str($resourceType)->replace('_', ' ')->headline(),
        };
    }
}
