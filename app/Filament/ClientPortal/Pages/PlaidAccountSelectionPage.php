<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PlaidAccountSelectionPage — FirmsVault Live Integrations, Checkpoint
 * 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.2/§4.3/§4.4). Selects
 * an institution via Plaid Link SDK's own client-side search — this
 * page's only FirmsVault-side responsibility is calling
 * `initiateLinkTokenConnection()` to obtain the `link_token` and mount
 * the SDK. Once connected, this page ALSO serves as the "Select
 * accounts" confirmation step (§4.4) — Plaid Link itself returns the
 * selected accounts at connect time, so this is a confirmation of what
 * was selected, never a server-side re-selection UI.
 *
 * ATTRIBUTION JUDGMENT CALL (disclosed): `startConnection()`/
 * `initiateLinkTokenConnection()`/`completeLinkTokenConnection()`
 * (provider-core, unmodified) require an int `$currentUserId` — no
 * ClientPortalUser-shaped actor concept exists in that service's
 * current signature, and this track's scope explicitly excludes
 * modifying `ProviderConnectionService`. This page attributes the
 * client-initiated connection to the ORIGINATING
 * `FinancialEvidenceMatterRequest.requested_by_firm_user_id`'s firm user
 * (the firm staff member who asked for this connection) — the firm
 * requested it, the client completes it — rather than inventing a new
 * actor concept unilaterally.
 *
 * FOUND AND FIXED (Checkpoint 7 authorization review, item 19 —
 * surfaced by a full-suite-only flake in the exchange controller's own
 * regression test): `$currentUserId` above resolves against
 * `FirmUser.user_id` (`ProviderConnectionService::resolveActingFirmUser()`
 * — a real `users.id`), never `FirmUser.id` directly. This page's
 * original code passed `requested_by_firm_user_id` — the column name
 * says exactly what it is, a `firm_users.id` (confirmed by both the
 * owning migration's `constrained('firm_users')` and this model's own
 * `requestedBy(): BelongsTo(FirmUser::class, ...)`) — straight through
 * as `$currentUserId`, which the ORIGINAL docblock above incorrectly
 * described as itself resolving "to a firm_users row." Whenever a
 * `FirmUser.id` differs from its own `user_id` (the overwhelming
 * majority of rows in any real multi-user database — the two id
 * sequences are independent), `resolveActingFirmUser()` throws
 * "User {id} has no active FirmUser membership in firm {id}," making
 * the entire Client-Portal-initiated Plaid connection flow — this
 * checkpoint's core feature — non-functional. Silently masked in
 * every existing test because a fresh, isolated test database's two
 * independent id sequences frequently coincide at small values. Fixed
 * by resolving the request's own `requestedBy` relation and passing
 * ITS `user_id`, at all three affected call sites below.
 */
class PlaidAccountSelectionPage extends Page
{
    #[Url]
    public ?string $matter = null;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Connect a Financial Account';

    public string $linkToken = '';

    public ?int $firmIntegrationId = null;

    public function mount(): void
    {
        $matterModel = $this->resolveMatterOrFail();

        $request = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => FinancialEvidenceMatterRequest::query()
            ->where('matter_id', $matterModel->id)
            ->where('status', 'pending')
            ->latest('requested_at')
            ->first());

        if ($request === null) {
            throw new NotFoundHttpException('No pending financial-data request for this matter.');
        }

        $provider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first();

        if ($provider === null) {
            throw new RuntimeException('Plaid is not registered as an integration provider.');
        }

        $connection = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, function () use ($matterModel, $provider, $request) {
            // requestingUserId() resolves the real users.id — see this
            // class's own docblock for why the raw
            // requested_by_firm_user_id column (a firm_users.id) must
            // never be passed to ProviderConnectionService directly.
            $requestingUserId = $this->requestingUserId($request);

            $connection = app(ProviderConnectionService::class)->startConnection(
                $matterModel->firm_id,
                $provider->id,
                $requestingUserId,
                requestedCapabilities: $request->requested_products_json,
            );

            // Checkpoint 7 fix: persist the request-to-connection binding
            // at the earliest possible point (before the client ever
            // sees a firm_integration_id) — see the owning migration's
            // docblock for the IDOR this closes.
            // `PlaidExchangeController::exchange()` trusts THIS column,
            // never a client-supplied firm_integration_id.
            $request->update(['firm_integration_id' => $connection->id]);

            $result = app(ProviderConnectionService::class)->initiateLinkTokenConnection($connection, $requestingUserId);
            $this->linkToken = $result->linkToken;

            return $connection;
        });

        $this->firmIntegrationId = $connection->id;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connect through Plaid')
                ->description('Sandbox-only in this environment. Select your institution and follow the prompts.')
                ->schema([
                    Text::make('Once connected, you will confirm the date range and consent on the next screen.'),
                ]),
        ]);
    }

    /**
     * Resolves the real `users.id` `ProviderConnectionService` requires
     * — never the raw `requested_by_firm_user_id` column value (a
     * `firm_users.id`) directly. See this class's own docblock for the
     * defect this closes.
     */
    private function requestingUserId(FinancialEvidenceMatterRequest $request): int
    {
        $requestingFirmUser = $request->requestedBy ?? FirmUser::query()->findOrFail($request->requested_by_firm_user_id);

        return $requestingFirmUser->user_id;
    }

    private function resolveMatterOrFail(): Matter
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null || $this->matter === null) {
            throw new AccessDeniedHttpException('No matter specified.');
        }

        $matterId = (int) $this->matter;
        $matterModel = (new TenantContextService)->runWithFirmContext($portalUser->client->firm_id, fn () => Matter::query()->find($matterId));

        if ($matterModel === null || ! app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matterModel)) {
            throw new AccessDeniedHttpException('You do not have access to this matter.');
        }

        return $matterModel;
    }

    public function render(): View
    {
        return view('filament-client-portal.plaid-link', [
            'linkToken' => $this->linkToken,
            'firmIntegrationId' => $this->firmIntegrationId,
            'matterId' => $this->matter,
        ]);
    }
}
