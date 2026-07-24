<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Actions;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\ProviderConnectionService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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

        $this->schema([
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
                ->native(false),
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

            try {
                $connection = $connectionService->startConnection(
                    (int) $firmUser->firm_id,
                    (int) $data['integration_provider_id'],
                    (int) Auth::id(),
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
}
