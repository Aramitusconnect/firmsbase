<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\EnterSupportAccessSessionAction;
use App\Filament\Actions\Platform\LeaveSupportAccessSessionAction;
use App\Filament\Actions\Platform\NudgeIntegrationQueueAsSupportAction;
use App\Filament\Actions\Platform\RequestSupportAccessAction;
use App\Filament\Actions\Platform\RevokeSupportAccessSessionAction;
use App\Integrations\Data\PlatformIntegrationConnectionSummary;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PlatformFirmIntegrationsPage — Checkpoint 11 (frozen-design-post-
 * security-review.md §2, §6, §7, §8, §12). Per-firm live drill-down:
 * lists one firm's `firm_integrations` connections and hosts the
 * support-access session lifecycle + on-demand queue nudge header
 * actions.
 *
 * Scalar-property-only architecture (frozen design §6): the ONLY public
 * property this class declares is `$firmUuid` (a plain string route
 * parameter) — never a Model. mount() re-resolves the Firm fresh from
 * that scalar every time it runs; every action/table closure below
 * re-resolves it again independently rather than trusting any
 * previously-hydrated value, and every read/action is wrapped in
 * TenantContextService::runWithFirmContext() (via
 * PlatformFirmIntegrationBoundedAccessService, never directly).
 */
class PlatformFirmIntegrationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'platform-firm-integrations/{firmUuid}';

    protected static ?string $title = 'Firm Integrations';

    public string $firmUuid = '';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public function mount(string $firmUuid): void
    {
        $this->firmUuid = $firmUuid;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            app(PlatformFirmIntegrationBoundedAccessService::class)->assertCanAccessFirm($admin, $firm);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (): string => "Firm: {$this->firmUuid}")->weight('bold'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Phase 2 query-hardening fix: this closure now accepts the
            // Filament-injected `page`/`recordsPerPage` values and
            // passes them straight through to
            // IntegrationPlatformOversightReadService::connectionsForFirm(),
            // which now performs a genuine DB-level LIMIT/OFFSET
            // (Eloquent's paginate()) instead of materializing every
            // connection this firm has ever had and slicing in PHP
            // afterward. Returning a real LengthAwarePaginator here
            // (rather than a Collection) is what lets Filament's own
            // pagination controls (see ->paginated() below) genuinely
            // drive the underlying query.
            ->records(function (int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $admin = Auth::guard('platform_admin')->user();

                $perPage = (int) $recordsPerPage;
                $pageNumber = (int) $page;

                if (! $admin instanceof PlatformAdmin) {
                    return new LengthAwarePaginator(collect(), 0, $perPage, $pageNumber);
                }

                $firm = Firm::findByUuid($this->firmUuid);

                try {
                    $connections = app(IntegrationPlatformOversightReadService::class)
                        ->connectionsForFirm($admin, $firm, $pageNumber, $perPage);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return new LengthAwarePaginator(collect(), 0, $perPage, $pageNumber);
                }

                return $connections->through(fn (PlatformIntegrationConnectionSummary $connection): array => [
                    'uuid' => $connection->uuid,
                    'display_label' => $connection->displayLabel,
                    'provider_display_name' => $connection->providerDisplayName,
                    'status' => $connection->status->value,
                    'health_summary_state' => $connection->healthSummaryState?->value,
                    'masked_external_account_id' => $connection->maskedExternalAccountId,
                    'webhook_routing_configured' => $connection->webhookRoutingConfigured,
                    'connected_at' => $connection->connectedAt,
                    'disconnected_at' => $connection->disconnectedAt,
                ]);
            })
            ->columns([
                TextColumn::make('display_label')->label('Connection'),
                TextColumn::make('provider_display_name')->label('Provider'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'disconnected' => 'gray',
                        'error', 'reauthorization_required' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('health_summary_state')->label('Health')->badge()->placeholder('—'),
                TextColumn::make('masked_external_account_id')->label('External account')->placeholder('—')->fontFamily('mono'),
                TextColumn::make('connected_at')->label('Connected at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('viewConnection')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => PlatformFirmIntegrationDetailPage::getUrl([
                        'firmUuid' => $this->firmUuid,
                        'connectionUuid' => $record['uuid'],
                    ])),
            ])
            ->emptyStateHeading('No connections')
            ->emptyStateDescription('This firm has not connected any integration yet.')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            RequestSupportAccessAction::make(),
            EnterSupportAccessSessionAction::make(),
            LeaveSupportAccessSessionAction::make(),
            RevokeSupportAccessSessionAction::make(),
            NudgeIntegrationQueueAsSupportAction::make(),
            $this->retentionSweepDryRunAction(),
        ];
    }

    /**
     * Retention sweep DRY-RUN preview only (frozen design §7) —
     * inlined directly on this page per the mission's established
     * "MAY be inlined... implementer discretion" allowance (mirrors
     * App\Filament\Firm\Resources\FirmIntegrationResource\Pages\
     * ViewFirmIntegration's own inlined actions) since it is not one of
     * the seven standalone action classes on the frozen file allowlist.
     */
    private function retentionSweepDryRunAction(): Action
    {
        return Action::make('retentionSweepDryRunPreview')
            ->label('Preview Retention Sweep (Dry Run)')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Preview Retention Sweep')
            ->modalDescription('Dispatches a dry-run retention sweep for this firm — no rows are deleted. Results are recorded to the retention sweep audit log, not shown inline here.')
            ->action(function (): void {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                    return;
                }

                $firm = Firm::findByUuid($this->firmUuid);

                try {
                    app(PlatformFirmIntegrationBoundedAccessService::class)->previewRetentionSweepDryRun($admin, $firm);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not preview retention sweep')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Retention sweep dry run dispatched')->success()->send();
            });
    }
}
