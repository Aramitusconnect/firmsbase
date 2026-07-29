<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\ClientPortalPlaidConnectionResolverService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * PlaidDateRangeConfirmationPage — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.5). Writes
 * `financial_evidence_matter_authorizations.authorized_date_range_start/_end`,
 * bounded by Plaid's own documented transaction-history retrieval
 * window (illustratively bounded to 24 months back in this UI, per the
 * design's own "bounded, never unbounded" discipline).
 *
 * FOUND AND FIXED (release-candidate remediation, defect C1 —
 * Critical). `continueToConsent()` resolved the connection it binds
 * this matter's authorization to as the firm's MOST RECENTLY CREATED
 * Active Plaid connection:
 *
 *     FirmIntegration::query()
 *         ->where('firm_id', $matterModel->firm_id)
 *         ->whereHas('integrationProvider', ...plaid...)
 *         ->where('status', 'active')
 *         ->latest('id')->first()
 *
 * — a firm-wide query with no matter, request, client, or consent
 * linkage of any kind. In a firm with more than one client connecting
 * an account (the ordinary case, not an attack), whichever client
 * happened to finish Plaid Link LAST owned the row every other client's
 * date-range confirmation then bound to: client A's authorized
 * retrieval window was written against client B's bank connection, and
 * `FinancialEvidenceMatterScopeService::connectedFirmIntegrationIds()`
 * — the shared "what may this matter read" resolver — then handed
 * matter A's workspace client B's financial evidence. Creation order
 * alone decided it; no tampering was required.
 *
 * Fixed by resolving through
 * `ClientPortalPlaidConnectionResolverService`, which reads the
 * server-authoritative
 * `financial_evidence_matter_requests.firm_integration_id` binding for
 * THIS matter's own request (set by `PlaidAccountSelectionPage::mount()`
 * at connection-creation time) and fails closed — 403/404 plus an
 * audited, secret-free denial event — when that binding is missing,
 * cross-firm, non-Plaid, mismatched, or not Active. See that service's
 * own docblock for the full analysis.
 */
class PlaidDateRangeConfirmationPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    #[Url]
    public ?string $matter = null;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Confirm Date Range';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'date_from' => now()->subMonths(24)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                Action::make('continue')->label('Continue to Consent')->action('continueToConsent'),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Authorized retrieval window')
                    ->description('Choose the date range the firm may retrieve transaction history for. Bounded by Plaid\'s documented retrieval window.')
                    ->schema([
                        DatePicker::make('date_from')->label('From')->native(false)->maxDate(now())->required(),
                        DatePicker::make('date_to')->label('To')->native(false)->maxDate(now())->required(),
                    ]),
            ]);
    }

    public function continueToConsent(): void
    {
        // resolveMatterOrFail() has already proven a non-null,
        // guard-authenticated portal user by the time this returns.
        $matterModel = $this->resolveMatterOrFail();
        /** @var ClientPortalUser $portalUser */
        $portalUser = Auth::guard('client')->user();

        // Authorization is resolved BEFORE any submitted form state is
        // read: the ONLY legitimate binding source is this matter's own
        // request's server-set firm_integration_id. Never
        // latest()/first() over firm_integrations, never a
        // client-submitted id. Throws (403/404) and audits rather than
        // returning null — a missing/mismatched/revoked binding must
        // never fall through to "carry on."
        /** @var FirmIntegration $connection */
        [, $connection] = app(ClientPortalPlaidConnectionResolverService::class)->resolveOrFail(
            $portalUser,
            $matterModel,
            allowedStatuses: [ConnectionStatus::Active],
            action: 'confirm_date_range',
        );

        $state = $this->form->getState();

        (new TenantContextService)->runWithFirmContext($matterModel->firm_id, function () use ($matterModel, $connection, $state) {
            FinancialEvidenceMatterAuthorization::query()
                ->where('matter_id', $matterModel->id)
                ->where('firm_integration_id', $connection->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $matterModel->firm_id,
                'matter_id' => $matterModel->id,
                'firm_integration_id' => $connection->id,
                'authorized_date_range_start' => $state['date_from'] ?? null,
                'authorized_date_range_end' => $state['date_to'] ?? null,
            ]);
        });

        $this->redirect(PlaidConsentPage::getUrl(['matter' => $matterModel->id, 'firmIntegration' => $connection->id]));
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
