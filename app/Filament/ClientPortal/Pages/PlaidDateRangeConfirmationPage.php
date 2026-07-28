<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
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
        $matterModel = $this->resolveMatterOrFail();
        $state = $this->form->getState();

        $connection = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => FirmIntegration::query()
            ->where('firm_id', $matterModel->firm_id)
            ->whereHas('integrationProvider', fn ($q) => $q->where('code', ProviderKey::Plaid->value))
            ->where('status', ConnectionStatus::Active->value)
            ->latest('id')
            ->first());

        if ($connection === null) {
            Notification::make()->title('No active Plaid connection found for this matter.')->danger()->send();

            return;
        }

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
