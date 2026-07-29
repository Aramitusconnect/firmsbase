<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\ClientPortalPlaidConnectionResolverService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * PlaidConsentPage — FirmsVault Live Integrations, Checkpoint 4 ("Plaid
 * financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.6/§4.7). Lists every
 * requested product/`ResourceType` in plain language; a single
 * "I consent" action writes a new `financial_evidence_client_consents`
 * row. "Decline" records a row with `granted_products_json = []`/
 * `declined_at` set, triggers the firm-side request's status to
 * `Declined`, and surfaces the Upload Fallback path immediately — the
 * documented decline-trigger fallback.
 *
 * §4.12's binding constraint (never surfaced here): no wholesale rate
 * (`provider_rate_card_entries.provider_cost_cents`) is ever read or
 * displayed on this page — the client consents to PRODUCTS, never
 * prices.
 *
 * FOUND AND FIXED (release-candidate remediation, defect H1 — High,
 * IDOR). `resolveConnectionOrFail()` took the connection id straight
 * from the client-suppliable `#[Url] $firmIntegration` Livewire
 * property and validated it against `firm_id` + provider ONLY — never
 * against the current matter's own `FinancialEvidenceMatterRequest`.
 * Editing one query-string integer let any authenticated portal client
 * write a `financial_evidence_client_consents` row against — and, via
 * the revoke header action below, disconnect — a DIFFERENT matter's
 * Plaid connection anywhere in the same firm. This is the identical
 * defect already closed in `PlaidExchangeController::exchange()`, and
 * it is now closed the identical way: the connection is resolved
 * server-side from `financial_evidence_matter_requests.firm_integration_id`
 * via `ClientPortalPlaidConnectionResolverService`, and the URL-bound
 * property is kept for UX only — cross-CHECKED against the
 * server-resolved value and rejected on mismatch, never the source of
 * authorization.
 *
 * FOUND AND FIXED (release-candidate remediation, defect H5 — High).
 * The "Revoke Connection" header action called
 * `ProviderConnectionService::disconnect($connection)` with no actor at
 * all. That method's own first guard throws
 * `RuntimeException('disconnect() requires either a FirmUser
 * $currentUserId or an admin $actorPlatformAdminId.')` unconditionally
 * when both are null, and the call was not wrapped in a try/catch (the
 * neighbouring `resolveConnectionOrFail()` call was) — so §4.9's ONLY
 * client-initiated disconnect path threw an uncaught 500 on every
 * single invocation and had never worked. Fixed by resolving the
 * server-authoritative connection, attributing the disconnect to the
 * originating request's `requestedBy` firm user (see
 * `revokingUserId()`'s own docblock for why that resolves through
 * `->user_id`, never `->id`), recording the local consent revocation +
 * audit event, and wrapping the whole action so a failure is a safe
 * notification rather than a 500 or a leaked provider message.
 */
class PlaidConsentPage extends Page
{
    #[Url]
    public ?string $matter = null;

    #[Url]
    public ?string $firmIntegration = null;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Review and Consent';

    private const PRODUCT_LABELS = [
        'bank_account' => 'Account details (name, type, mask)',
        'transaction' => 'Transaction history',
        'income' => 'Income data',
        'liability' => 'Liabilities (loans, credit)',
        'investment' => 'Investment holdings and transactions',
        'statement' => 'Bank statements',
        'identity' => 'Identity/owner details on the account',
    ];

    public function content(Schema $schema): Schema
    {
        $matterModel = $this->resolveMatterOrFail();

        $requestedProducts = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => FinancialEvidenceMatterRequest::query()
            ->where('matter_id', $matterModel->id)
            ->latest('requested_at')
            ->value('requested_products_json')) ?? [];

        $labels = collect($requestedProducts)->map(fn (string $p) => self::PRODUCT_LABELS[$p] ?? $p)->values()->all();

        return $schema->components([
            Section::make('The firm is requesting access to:')
                ->schema([
                    UnorderedList::make($labels === [] ? ['No specific products listed'] : $labels),
                ]),
            Actions::make([
                Action::make('consent')->label('I Consent')->color('success')->action('grantConsent'),
                Action::make('decline')->label('Decline')->color('danger')->requiresConfirmation()->action('declineConsent'),
            ]),
        ]);
    }

    /**
     * §4.9 — the client-facing revoke is intentionally the ONLY
     * client-initiated disconnect path; firm staff has a separate one
     * (`PlaidItemResource`'s View page), both converging on the same,
     * unmodified `ProviderConnectionService::disconnect()` call.
     *
     * Every connection state is accepted here, INCLUDING
     * `Disconnected`: `disconnect()` is itself idempotent (a repeat call
     * against an already-Disconnected row returns it untouched — no
     * second outbound revoke, no `disconnected_at` overwrite), and the
     * local consent/authorization revocation below is written with the
     * same idempotent discipline, so a client who clicks Revoke twice
     * (or reloads and clicks again) gets a second success notification
     * rather than a fail-closed denial for a connection they legitimately
     * own.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label('Revoke Connection')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->hasRevocableConnection())
                ->action(function (): void {
                    try {
                        $matterModel = $this->resolveMatterOrFail();
                        /** @var ClientPortalUser $portalUser */
                        $portalUser = Auth::guard('client')->user();

                        [$request, $connection] = app(ClientPortalPlaidConnectionResolverService::class)->resolveOrFail(
                            $portalUser,
                            $matterModel,
                            allowedStatuses: ConnectionStatus::cases(),
                            action: 'revoke_connection',
                            clientSuppliedFirmIntegrationId: $this->firmIntegration,
                        );
                    } catch (Throwable) {
                        // A resolution failure is already audited by the
                        // resolver and is deliberately indistinguishable
                        // to the client — never echo the underlying
                        // reason back to a potential prober.
                        Notification::make()
                            ->title('Connection not available')
                            ->body('This connection is no longer linked to this matter. Contact your firm if you believe this is an error.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        // disconnect() already performs the official
                        // provider-side removal (Plaid /item/remove),
                        // revokes every Active local credential, clears
                        // the webhook routing token/index and the Plaid
                        // item-routing row — i.e. it is ALSO the
                        // stop-future-sync mechanism. Never duplicated
                        // here; only called correctly, with a real
                        // `users.id` actor.
                        app(ProviderConnectionService::class)->disconnect(
                            $connection,
                            currentUserId: $this->revokingUserId($request),
                        );

                        $this->recordClientRevocation($matterModel, $request, $connection);
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Could not revoke this connection')
                            ->body('Something went wrong while revoking. Please try again, or contact your firm.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Connection revoked')
                        ->body('The firm can no longer retrieve new financial data from this account.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Whether a server-authoritative, revocable connection currently
     * exists for this matter — the header action's visibility gate.
     * Deliberately NOT `$this->firmIntegration !== null`: that property
     * is client-controlled and proves nothing (it could name another
     * matter's connection, or be absent for a perfectly revocable one).
     */
    private function hasRevocableConnection(): bool
    {
        try {
            $matterModel = $this->resolveMatterOrFail();
            /** @var ClientPortalUser $portalUser */
            $portalUser = Auth::guard('client')->user();

            // canResolve(), not resolveOrFail(): a visibility probe runs
            // on EVERY render and must not write a denial audit row each
            // time a client simply has no connection bound yet. The
            // action itself re-resolves through resolveOrFail(), WITH the
            // client-supplied-id cross-check — this gate can only ever
            // hide a button, never authorize anything.
            return app(ClientPortalPlaidConnectionResolverService::class)->canResolve(
                $portalUser,
                $matterModel,
                allowedStatuses: ConnectionStatus::cases(),
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The real `users.id` `ProviderConnectionService::disconnect()`
     * requires. A `ClientPortalUser` has no `FirmUser` concept and this
     * task's scope explicitly excludes modifying
     * `ProviderConnectionService`, so this follows the attribution
     * judgment call already established and disclosed in
     * `PlaidAccountSelectionPage`'s own docblock: the client-initiated
     * action is attributed to the ORIGINATING request's
     * `requested_by_firm_user_id` firm user — the firm staff member who
     * asked for this connection in the first place.
     *
     * Resolved through `requestedBy->user_id`, NEVER the raw
     * `requested_by_firm_user_id` column (a `firm_users.id`) — that is
     * exactly the H4-class defect fixed at three call sites in
     * `PlaidAccountSelectionPage` and one in `PlaidExchangeController`:
     * `resolveActingFirmUser()` looks the actor up by `user_id`, so
     * passing a `firm_users.id` throws "User {id} has no active FirmUser
     * membership in firm {id}" for every row where the two independent
     * id sequences do not coincidentally match.
     */
    private function revokingUserId(FinancialEvidenceMatterRequest $request): int
    {
        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request): int {
            $requestingFirmUser = $request->requestedBy
                ?? FirmUser::query()->findOrFail($request->requested_by_firm_user_id);

            return (int) $requestingFirmUser->user_id;
        });
    }

    /**
     * The local half of a client-initiated revoke. Append-only and
     * idempotent — nothing is ever deleted, matching this checkpoint's
     * evidentiary discipline:
     *
     *   - supersedes this matter's live
     *     `financial_evidence_matter_authorizations` row(s) for this
     *     connection, which is what actually removes access: every
     *     workspace panel and detection service reads through
     *     `FinancialEvidenceMatterScopeService::connectedFirmIntegrationIds()`,
     *     which only ever returns non-superseded rows;
     *   - appends a new `financial_evidence_client_consents` row with
     *     `declined_at` set and `granted_products_json = []` — the
     *     existing consent-withdrawal shape `declineConsent()` already
     *     writes (this table has no in-place status column; a later row
     *     supersedes an earlier one, never edits it);
     *   - flips the originating request back to the existing `declined`
     *     status (NOT `cancelled` — cancellation is the firm's own
     *     action and would additionally make the request unresolvable,
     *     breaking repeat-revoke idempotency);
     *   - records one secret-free timeline event, in ADDITION to the
     *     `integration_oauth.disconnect`/`credential_revoked` events
     *     `disconnect()` itself already wrote.
     */
    private function recordClientRevocation(
        Matter $matterModel,
        FinancialEvidenceMatterRequest $request,
        FirmIntegration $connection,
    ): void {
        (new TenantContextService)->runWithFirmContext($matterModel->firm_id, function () use ($matterModel, $request, $connection): void {
            FinancialEvidenceMatterAuthorization::query()
                ->where('firm_id', $matterModel->firm_id)
                ->where('matter_id', $matterModel->id)
                ->where('firm_integration_id', $connection->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            FinancialEvidenceClientConsent::query()->create([
                'firm_id' => $matterModel->firm_id,
                'client_id' => $matterModel->client_id,
                'matter_id' => $matterModel->id,
                'matter_request_id' => $request->id,
                'firm_integration_id' => $connection->id,
                'granted_products_json' => [],
                'declined_at' => now(),
                'ip_address' => request()->ip(),
            ]);

            if ($request->status !== 'declined') {
                $request->update(['status' => 'declined']);
            }
        });

        app(ClientPortalPlaidConnectionResolverService::class)->recordEvent(
            $matterModel,
            ClientPortalPlaidConnectionResolverService::REVOCATION_EVENT_TYPE,
            [
                'matter_id' => (int) $matterModel->id,
                'matter_request_id' => (int) $request->id,
                'firm_integration_id' => (int) $connection->id,
                'client_id' => $matterModel->client_id === null ? null : (int) $matterModel->client_id,
                'initiated_by' => 'client_portal',
            ],
        );
    }

    public function grantConsent(): void
    {
        $matterModel = $this->resolveMatterOrFail();
        // Both the request AND the connection now come from the same
        // single server-side resolution, so the consent row can never
        // record a matter_request_id and a firm_integration_id that
        // belong to different requests (the pre-fix code resolved them
        // through two independent queries).
        [$request, $connection] = $this->resolveConnectionOrFail($matterModel, 'grant_consent');

        (new TenantContextService)->runWithFirmContext($matterModel->firm_id, function () use ($matterModel, $connection, $request) {
            FinancialEvidenceClientConsent::query()->create([
                'firm_id' => $matterModel->firm_id,
                'client_id' => $matterModel->client_id,
                'matter_id' => $matterModel->id,
                'matter_request_id' => $request->id,
                'firm_integration_id' => $connection->id,
                'granted_products_json' => $request->requested_products_json ?? [],
                'granted_at' => now(),
                'ip_address' => request()->ip(),
            ]);

            $request->update(['status' => 'consented']);
        });

        Notification::make()->title('Consent recorded — connection complete')->success()->send();

        $this->redirect(PlaidRequestReviewPage::getUrl());
    }

    public function declineConsent(): void
    {
        $matterModel = $this->resolveMatterOrFail();

        $requestedProducts = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => FinancialEvidenceMatterRequest::query()
            ->where('matter_id', $matterModel->id)
            ->latest('requested_at')
            ->first());

        (new TenantContextService)->runWithFirmContext($matterModel->firm_id, function () use ($matterModel, $requestedProducts) {
            FinancialEvidenceClientConsent::query()->create([
                'firm_id' => $matterModel->firm_id,
                'client_id' => $matterModel->client_id,
                'matter_id' => $matterModel->id,
                'matter_request_id' => $requestedProducts?->id,
                'firm_integration_id' => null,
                'granted_products_json' => [],
                'declined_at' => now(),
                'ip_address' => request()->ip(),
            ]);

            $requestedProducts?->update(['status' => 'declined']);
        });

        Notification::make()->title('Declined — you may upload documents instead')->warning()->send();

        $this->redirect(PlaidUploadFallbackPage::getUrl(['matter' => $matterModel->id]));
    }

    /**
     * Defect H1's fix. The connection is resolved ENTIRELY server-side —
     * authenticated client (`Auth::guard('client')`) -> current matter ->
     * that matter's own `FinancialEvidenceMatterRequest` -> ITS
     * `firm_integration_id` — exactly as `PlaidExchangeController` and
     * `PlaidAccountSelectionPage` already do. `$this->firmIntegration`
     * (the `#[Url]` property) is passed only to be cross-CHECKED against
     * the server-resolved id; it can never widen what is reachable, and
     * a mismatch is rejected and audited.
     *
     * @return array{0: FinancialEvidenceMatterRequest, 1: FirmIntegration}
     */
    private function resolveConnectionOrFail(Matter $matterModel, string $action): array
    {
        /** @var ClientPortalUser $portalUser */
        $portalUser = Auth::guard('client')->user();

        return app(ClientPortalPlaidConnectionResolverService::class)->resolveOrFail(
            $portalUser,
            $matterModel,
            allowedStatuses: [ConnectionStatus::Active],
            action: $action,
            clientSuppliedFirmIntegrationId: $this->firmIntegration,
        );
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
}
