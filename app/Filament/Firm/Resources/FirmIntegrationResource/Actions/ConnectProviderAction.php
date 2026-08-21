<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Actions;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ProviderMetadata;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\ProviderConnectionService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ConnectProviderAction — Checkpoint 10 (frozen-design-post-security-
 * review.md §12; §11.1's Action-based, never Form-backed Create/Edit
 * page ruling; §11.5's OAuth redirect design). A header action on
 * ListFirmIntegrations: provider selection only, no other user-entered
 * fields — connecting a new provider never needs a full Filament
 * Form-backed CreateRecord page, which structurally reduces the risk of
 * any default model-bound form fill path ever being pointed, even by
 * accident, at credential-adjacent fields.
 *
 * Calls the new ProviderConnectionService::startConnection() (frozen
 * design §2), then redirects same-origin to the EXISTING
 * `integrations.oauth.initiate` route with the new row's uuid — no new
 * controller/route is introduced here.
 *
 * TOCTOU discipline (frozen design §10): the action() closure below
 * never trusts anything beyond the submitted provider id and the
 * CURRENT request's freshly-resolved authenticated user/firm —
 * startConnection() itself re-resolves the acting FirmUser and re-runs
 * assertEnabled()/assertCanConnect() unconditionally every single call,
 * exactly as every other mutating action in this checkpoint requires.
 * There is no `mount()`-hydrated record here at all (this action has no
 * target record — it CREATES one), so there is nothing to re-fetch;
 * the discipline instead means never trusting anything cached about the
 * acting user's role/entitlement from a prior render.
 *
 * ->visible() below is UX only (hides the button entirely for a
 * disentitled firm or an insufficiently-privileged role, never merely
 * greys it out) — never a substitute for the real, synchronous boundary
 * enforced inside startConnection() itself.
 *
 * FirmsVault Live Integrations, Checkpoint 2 amendment
 * (checkpoint2-combined-design.md §4; checkpoint2-design-ui.md §1/§2):
 * this modal is now a 2-step wizard (Filament's Action::steps(), backed
 * by the framework's Filament\Actions\Concerns\HasWizard trait — no new
 * UI pattern invented). Step 1 is the existing provider Select plus a
 * new reactive `CheckboxList::make('capabilities')`, options driven
 * entirely by `ProviderMetadata::fromProvider($resolvedProvider)->resourceTypes`
 * (never a hand-maintained per-provider list), following the exact
 * `->live()`/`Get $get` reactive-field convention this codebase already
 * uses elsewhere (see e.g.
 * App\Filament\Actions\Platform\RequestSupportAccessAction's
 * `access_type` Select / `emergency_justification` Textarea pair).
 *
 * COMM-008 fix: the options list further filters out any resource type
 * `isDeadEndCapability()` flags as unmaterializable by the pull-sync
 * framework (currently just `ResourceType::Message` for any provider
 * other than Plaid — see that method's docblock, which mirrors
 * PullSyncJob::applyPage()'s exact gating condition) so this wizard
 * never requests real OAuth consent (e.g. Mail.Read/Mail.Send) for a
 * capability whose synced items are unconditionally discarded.
 * Step 2 discloses, for each selected capability, its human label and
 * the raw OAuth scopes it requires, read from
 * `SupportsOAuthContract::capabilityScopeMap()` — guarded by an
 * `instanceof SupportsOAuthContract` check throughout, so a future
 * non-OAuth provider (e.g. an API-key-only adapter) never breaks this
 * modal; step 2 simply never becomes visible for such a provider,
 * degrading gracefully rather than erroring. `capabilityScopeMap()` and
 * `requiredOAuthScopes` are documentation/UI-only — never authoritative
 * for what a provider actually granted (that remains
 * `scopes_granted_json`, written exclusively by
 * ProviderConnectionService::finishCallback()).
 *
 * The selected capabilities are threaded into
 * ProviderConnectionService::startConnection()'s new optional trailing
 * `$requestedCapabilities` parameter; the OAuth-initiate redirect itself
 * is completely unchanged (same route, same controller, same service
 * call as before this checkpoint).
 */
class ConnectProviderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'connectProvider';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Connect Provider');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->steps([
            Step::make('Choose a provider')
                ->schema([
                    Select::make('integration_provider_id')
                        ->label('Provider')
                        // Checkpoint 12 addition (frozen-design-post-security-
                        // review.md §2 F1): the catalog row alone
                        // (status='active') is not sufficient — a row can exist
                        // in integration_providers without its ProviderKey
                        // being currently resolvable via ProviderRegistry (e.g.
                        // an environment-gated provider that is currently
                        // disabled). Filtering both closes the confirmed §18
                        // violation of an unfiltered dropdown rendering in
                        // every environment.
                        ->options(static function (ProviderRegistry $registry): array {
                            return IntegrationProvider::query()
                                ->where('status', 'active')
                                ->orderBy('display_name')
                                ->get(['id', 'display_name', 'code'])
                                ->filter(static function (IntegrationProvider $provider) use ($registry): bool {
                                    $key = ProviderKey::tryFrom($provider->code);

                                    return $key !== null && $registry->has($key);
                                })
                                ->pluck('display_name', 'id')
                                ->all();
                        })
                        ->required()
                        ->native(false)
                        ->live(),

                    // FirmsVault Live Integrations, Checkpoint 2 addition
                    // (checkpoint2-combined-design.md §4; checkpoint2-design-ui.md
                    // §1). Reactive, keyed off the provider Select above via
                    // the exact same ->live()/Get $get pattern this codebase
                    // already uses (see class docblock). Options are the
                    // resolved provider's own ProviderMetadata::resourceTypes
                    // — never a hardcoded per-provider list — so a provider
                    // with zero resource types (or none selected yet) simply
                    // never shows this field, rather than rendering a
                    // blocking empty state.
                    CheckboxList::make('capabilities')
                        ->label('What should this connection sync?')
                        ->helperText('Choose only the data this firm needs. You can add more later from the connection page.')
                        ->options(function (Get $get, ProviderRegistry $registry): array {
                            $resolvedProvider = self::resolveProviderFromId($get('integration_provider_id'), $registry);

                            if ($resolvedProvider === null) {
                                return [];
                            }

                            return collect(ProviderMetadata::fromProvider($resolvedProvider)->resourceTypes)
                                ->reject(fn (string $type): bool => self::isDeadEndCapability($type, $resolvedProvider))
                                ->mapWithKeys(fn (string $type): array => [$type => self::capabilityLabel($type)])
                                ->all();
                        })
                        ->required()
                        ->live()
                        ->visible(fn (Get $get): bool => filled($get('integration_provider_id'))),
                ]),

            // FirmsVault Live Integrations, Checkpoint 2 addition
            // (checkpoint2-combined-design.md §4; checkpoint2-design-ui.md
            // §2). Only rendered at all when the resolved provider
            // implements SupportsOAuthContract — a provider that doesn't
            // (e.g. a future API-key-only adapter) simply never shows this
            // step, per this file's own class docblock. capabilityScopeMap()
            // is documentation/UI-only, same status as requiredOAuthScopes —
            // never treated as authoritative for what is actually granted.
            Step::make('Review permissions')
                ->visible(function (Get $get, ProviderRegistry $registry): bool {
                    return self::resolveProviderFromId($get('integration_provider_id'), $registry) instanceof SupportsOAuthContract;
                })
                ->schema([
                    TextEntry::make('scope_disclosure')
                        ->hiddenLabel()
                        ->state(function (Get $get, ProviderRegistry $registry): array {
                            $resolvedProvider = self::resolveProviderFromId($get('integration_provider_id'), $registry);

                            if (! $resolvedProvider instanceof SupportsOAuthContract) {
                                return ['Select a provider to review the permissions this connection will request.'];
                            }

                            $selectedCapabilities = array_values((array) ($get('capabilities') ?? []));

                            if ($selectedCapabilities === []) {
                                return ['No capabilities selected yet.'];
                            }

                            $scopeMap = $resolvedProvider->capabilityScopeMap();

                            return collect($selectedCapabilities)
                                ->map(function (string $capability) use ($scopeMap): string {
                                    $scopes = $scopeMap[$capability] ?? [];

                                    return $scopes === []
                                        ? self::capabilityLabel($capability)
                                        : self::capabilityLabel($capability).' — requires: '.implode(', ', $scopes);
                                })
                                ->all();
                        })
                        ->listWithLineBreaks(),
                ]),
        ]);

        $this->modalHeading('Connect a Provider');
        $this->modalSubmitActionLabel('Continue to Provider');

        $this->visible(static function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                return false;
            }

            return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
                && app(IntegrationAccessPolicyService::class)->canConnect($firmUser->role);
        });

        $this->action(function (array $data, ProviderConnectionService $connectionService): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('No active firm membership.')->danger()->send();

                return;
            }

            // FirmsVault Live Integrations, Checkpoint 2 (checkpoint2-combined-design.md
            // §4): null (not an empty array) when the capabilities field
            // was never rendered/submitted (a provider with zero resource
            // types) — startConnection() treats null as "no validation
            // performed", preserving that provider's exact prior behavior.
            $requestedCapabilities = array_key_exists('capabilities', $data)
                ? array_values((array) $data['capabilities'])
                : null;

            try {
                $connection = $connectionService->startConnection(
                    (int) $firmUser->firm_id,
                    (int) $data['integration_provider_id'],
                    (int) Auth::id(),
                    $requestedCapabilities,
                );
            } catch (RuntimeException $e) {
                Notification::make()
                    ->title('Could not start connection')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return;
            }

            $this->redirect(route('integrations.oauth.initiate', $connection));
        });
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 2 addition. Resolves the
     * submitted `integration_provider_id` field value to a live provider
     * instance, applying the EXACT same two-part check (catalog row
     * exists AND its ProviderKey currently resolves via ProviderRegistry)
     * as the provider Select's own ->options() filter above — never
     * trusts the submitted id alone. Returns null on any failure (no
     * value submitted yet, unknown catalog row, unresolvable/disabled
     * provider key) so every reactive closure that calls this can
     * degrade to an empty/hidden state rather than throwing mid-render.
     */
    private static function resolveProviderFromId(mixed $providerId, ProviderRegistry $registry): ?IntegrationProviderContract
    {
        if (! filled($providerId)) {
            return null;
        }

        $provider = IntegrationProvider::query()->find($providerId);
        $key = ProviderKey::tryFrom($provider?->code ?? '');

        if ($key === null || ! $registry->has($key)) {
            return null;
        }

        return $registry->get($key);
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 2 addition
     * (checkpoint2-design-ui.md §1). Human-readable capability labels for
     * the four `ResourceType` cases the mission brief names explicitly —
     * any other resource type (e.g. TestProvider's `Task`) falls back to
     * a headline-cased rendering of its raw value rather than being
     * omitted, so the CheckboxList never silently drops an option.
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

    /**
     * COMM-008 fix. PullSyncJob::applyPage() only ever materializes a
     * local record for an unmapped external item when
     * `$connection->providerKey() === ProviderKey::Plaid`
     * (app/Jobs/PullSyncJob.php, ~line 896) — every other provider falls
     * straight through to SyncItemStatus::Skipped and nothing is ever
     * kept locally, for ANY resource type. `ResourceType::Message`
     * specifically is never handled even in the Plaid branch
     * (FinancialEvidenceMaterializerService::materialize()'s match has
     * no Message case at all), so offering it as a capability for a
     * Microsoft365/GoogleWorkspace connection requests real mailbox
     * OAuth consent (Mail.Read/Mail.Send or the Gmail equivalent) for a
     * sync result that is discarded outright.
     *
     * This mirrors that exact gating condition here — using the
     * resolved provider's own key() rather than a FirmIntegration model,
     * since this wizard runs before any connection row exists — so the
     * two stay consistent: Message is only ever offered for a provider
     * whose sync framework can actually keep it (currently: none, since
     * Plaid itself never selects Message as a resource type; this stays
     * keyed off Plaid rather than hardcoded to "never" so a future
     * provider that both is Plaid-keyed and gains real Message handling
     * does not need this file touched again).
     */
    private static function isDeadEndCapability(string $resourceType, IntegrationProviderContract $resolvedProvider): bool
    {
        return $resourceType === ResourceType::Message->value
            && $resolvedProvider->key() !== ProviderKey::Plaid;
    }
}
