<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
            \Filament\Schemas\Components\Actions::make([
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
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label('Revoke Connection')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->firmIntegration !== null)
                ->action(function (): void {
                    $matterModel = $this->resolveMatterOrFail();

                    try {
                        $connection = $this->resolveConnectionOrFail($matterModel);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    app(ProviderConnectionService::class)->disconnect($connection);

                    Notification::make()->title('Connection revoked')->success()->send();
                }),
        ];
    }

    public function grantConsent(): void
    {
        $matterModel = $this->resolveMatterOrFail();
        $connection = $this->resolveConnectionOrFail($matterModel);

        $requestedProducts = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => FinancialEvidenceMatterRequest::query()
            ->where('matter_id', $matterModel->id)
            ->latest('requested_at')
            ->first());

        (new TenantContextService)->runWithFirmContext($matterModel->firm_id, function () use ($matterModel, $connection, $requestedProducts) {
            FinancialEvidenceClientConsent::query()->create([
                'firm_id' => $matterModel->firm_id,
                'client_id' => $matterModel->client_id,
                'matter_id' => $matterModel->id,
                'matter_request_id' => $requestedProducts?->id,
                'firm_integration_id' => $connection->id,
                'granted_products_json' => $requestedProducts?->requested_products_json ?? [],
                'granted_at' => now(),
                'ip_address' => request()->ip(),
            ]);

            $requestedProducts?->update(['status' => 'consented']);
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

    private function resolveConnectionOrFail(Matter $matterModel): FirmIntegration
    {
        if ($this->firmIntegration === null) {
            throw new RuntimeException('No connection specified.');
        }

        $connection = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => FirmIntegration::query()
            ->where('id', (int) $this->firmIntegration)
            ->where('firm_id', $matterModel->firm_id)
            ->whereHas('integrationProvider', fn ($q) => $q->where('code', ProviderKey::Plaid->value))
            ->first());

        if ($connection === null) {
            throw new RuntimeException('Connection not found.');
        }

        return $connection;
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
