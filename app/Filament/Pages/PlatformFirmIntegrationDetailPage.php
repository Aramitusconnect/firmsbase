<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\RequeueOutboxEventAsSupportAction;
use App\Filament\Actions\Platform\RequeueSyncItemAsSupportAction;
use App\Integrations\Data\PlatformIntegrationConnectionSummary;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PlatformFirmIntegrationDetailPage — Checkpoint 11 (frozen-design-
 * post-security-review.md §2, §6, §7, §10, §12). Single-connection
 * drill-down: health, sync history, failed items (with requeue row
 * actions), webhook status (never the token), conflicts (metadata only,
 * resolution note gated behind an active support-access session), and
 * sanitized audit history.
 *
 * Scalar-property-only architecture (frozen design §6): the only public
 * properties are `$firmUuid`/`$connectionUuid` (plain route-parameter
 * strings) — never a Model. Every section below is computed fresh
 * inside content()/table(), re-resolving the Firm and connection detail
 * from those two scalars on every render — never cached on `$this`
 * between requests. content() and table() are independently re-invoked
 * by Filament on every render pass and are NOT the same call, so each
 * re-derives its own data rather than sharing state via a property.
 */
class PlatformFirmIntegrationDetailPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'platform-firm-integrations/{firmUuid}/{connectionUuid}';

    protected static ?string $title = 'Connection Detail';

    public string $firmUuid = '';

    public string $connectionUuid = '';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public function mount(string $firmUuid, string $connectionUuid): void
    {
        $this->firmUuid = $firmUuid;
        $this->connectionUuid = $connectionUuid;

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

        if (app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $this->connectionUuid) === null) {
            abort(404);
        }
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        $firm = Firm::findByUuid($this->firmUuid);
        $readService = app(IntegrationPlatformOversightReadService::class);

        try {
            $connection = $readService->connectionDetail($admin, $firm, $this->connectionUuid);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($connection === null) {
            return $schema->components([
                Text::make('This connection could not be found for this firm.')->color('danger'),
            ]);
        }

        return $schema->components([
            $this->connectionSection($connection),
            $this->healthSection($connection),
            $this->usageSection($admin, $firm, $connection, $readService),
            $this->syncHistorySection($admin, $firm, $connection, $readService),
            Section::make('Failed Items')
                ->description('Dead-lettered outbox events and failed-permanent sync items for this connection. last_error is never displayed here (frozen design §10) — only the governed health diagnostic fields above.')
                ->schema([EmbeddedTable::make()])
                ->collapsible(),
            $this->conflictsSection($admin, $firm, $connection, $readService),
            $this->retentionSection($admin, $readService),
            $this->auditHistorySection($admin, $firm, $readService),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): \Illuminate\Support\Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $firm = Firm::findByUuid($this->firmUuid);
                $readService = app(IntegrationPlatformOversightReadService::class);

                $connection = $readService->connectionDetail($admin, $firm, $this->connectionUuid);

                if ($connection === null) {
                    return collect();
                }

                try {
                    return $readService->failedItemsForConnection($admin, $firm, $connection->id);
                } catch (RuntimeException $e) {
                    return collect();
                }
            })
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'outbox_event' ? 'Outbox Event' : 'Sync Item')
                    ->color(fn (string $state): string => $state === 'outbox_event' ? 'info' : 'warning'),
                TextColumn::make('label')->label('Resource / Event'),
                TextColumn::make('detail')->label('Detail'),
                TextColumn::make('failed_at')->label('Failed at')->dateTime(),
                TextColumn::make('requeue_count')->label('Requeues')->alignEnd(),
            ])
            ->emptyStateHeading('No failed items')
            ->emptyStateDescription('Nothing needs requeuing for this connection right now.')
            ->recordActions([
                RequeueOutboxEventAsSupportAction::make(),
                RequeueSyncItemAsSupportAction::make(),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->toolbarActions([]);
    }

    private function connectionSection(PlatformIntegrationConnectionSummary $connection): Section
    {
        return Section::make('Connection')
            ->schema([
                Text::make(fn (): string => "Provider: {$connection->providerDisplayName}"),
                Text::make(fn (): string => "Display label: {$connection->displayLabel}"),
                Text::make(fn (): string => 'Status: '.$connection->status->value)->badge(),
                Text::make(fn (): string => 'Connected at: '.($connection->connectedAt?->toDayDateTimeString() ?? '—')),
                Text::make(fn (): string => 'Disconnected at: '.($connection->disconnectedAt?->toDayDateTimeString() ?? '—')),
                Text::make(fn (): string => 'External account (masked): '.($connection->maskedExternalAccountId ?? '—')),
                Text::make(fn (): string => 'Webhook routing configured: '.($connection->webhookRoutingConfigured ? 'Yes' : 'No').' (the routing token itself is never shown here — frozen design §10)'),
            ]);
    }

    private function healthSection(PlatformIntegrationConnectionSummary $connection): Section
    {
        return Section::make('Health')
            ->schema([
                Text::make(fn (): string => 'Summary state: '.($connection->healthSummaryState?->value ?? '—')),
                Text::make(fn (): string => 'Diagnostic: '.($connection->sanitizedDiagnosticSummary ?? '—')),
                Text::make(fn (): string => 'Last failure category: '.($connection->lastFailureCategory ?? '—')),
                Text::make(fn (): string => 'Consecutive failures: '.$connection->consecutiveFailures),
                Text::make(fn (): string => 'Next retry at: '.($connection->nextRetryAt?->toDayDateTimeString() ?? '—')),
            ])
            ->collapsible();
    }

    /**
     * The required "usage" sub-view (frozen design §7). Gated on top of
     * the coarse assertCanAccessFirm() check (already enforced by
     * IntegrationPlatformOversightReadService::usageForFirm() via
     * readWithinFirmAccess()) by
     * PlatformStaffAccessPolicyService::canAccessPlatformBilling()
     * directly (frozen design §11/§12 — no new policy method), re-
     * checked fresh inside this closure on every render, never trusted
     * from mount()-time alone — mirrors this file's own established
     * TOCTOU discipline for every other sub-view/action.
     *
     * Security review Finding 3: usageForFirm() itself now ALSO asserts
     * canAccessPlatformBilling() internally (the authoritative gate —
     * see IntegrationPlatformOversightReadService's class docblock).
     * This closure's own check is kept deliberately, as belt-and-
     * suspenders: it renders a friendly denial reason in place of the
     * list; letting the service's exception propagate here instead would
     * surface as an unhandled error on the page rather than a graceful
     * denial message.
     */
    private function usageSection(
        PlatformAdmin $admin,
        Firm $firm,
        PlatformIntegrationConnectionSummary $connection,
        IntegrationPlatformOversightReadService $readService,
    ): Section {
        return Section::make('Usage')
            ->description('Aggregate, sanitized usage counts for this connection. No raw provider payload or billing/cost figure is ever shown here — IntegrationUsageRecord has no such column.')
            ->schema([
                UnorderedList::make(function () use ($admin, $firm, $connection, $readService): array {
                    $decision = app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin);

                    if (! $decision->allowed) {
                        return [$decision->reason ?? 'You are not permitted to view usage data for this connection.'];
                    }

                    $rows = $readService->usageForFirm($admin, $firm)
                        ->filter(fn (array $row): bool => $row['firm_integration_id'] === $connection->id)
                        ->map(fn (array $row): string => sprintf(
                            '%s / %s (%s%s) — %d %s — first %s, last %s',
                            $row['provider_key'] ?? '—',
                            $row['capability'] ?? '—',
                            $row['operation_type'] ?? '—',
                            $row['direction'] !== null ? ", {$row['direction']}" : '',
                            $row['total_quantity'] ?? 0,
                            $row['unit'] ?? '',
                            optional($row['first_occurred_at'])->toDayDateTimeString() ?? '—',
                            optional($row['last_occurred_at'])->toDayDateTimeString() ?? '—',
                        ));

                    return $rows->isEmpty() ? ['No usage has been recorded for this connection yet.'] : $rows->all();
                }),
            ])
            ->collapsible();
    }

    private function syncHistorySection(
        PlatformAdmin $admin,
        Firm $firm,
        PlatformIntegrationConnectionSummary $connection,
        IntegrationPlatformOversightReadService $readService,
    ): Section {
        return Section::make('Sync History')
            ->icon(Heroicon::OutlinedArrowPath)
            ->schema([
                UnorderedList::make(function () use ($admin, $firm, $connection, $readService): array {
                    $rows = $readService->syncHistoryForConnection($admin, $firm, $connection->id)
                        ->map(fn (array $run): string => sprintf(
                            '%s (%s) — %s — %d/%d succeeded — started %s',
                            $run['resource_type'] ?? 'unknown',
                            $run['sync_direction'] ?? '—',
                            $run['status'] ?? '—',
                            $run['items_succeeded'] ?? 0,
                            $run['items_total'] ?? 0,
                            optional($run['started_at'])->toDayDateTimeString() ?? '—',
                        ));

                    return $rows->isEmpty() ? ['No sync runs recorded for this connection yet.'] : $rows->all();
                }),
            ])
            ->collapsible();
    }

    private function conflictsSection(
        PlatformAdmin $admin,
        Firm $firm,
        PlatformIntegrationConnectionSummary $connection,
        IntegrationPlatformOversightReadService $readService,
    ): Section {
        return Section::make('Conflicts')
            ->description('local_value/external_value are never rendered (frozen design §10). The resolution note is only shown while you hold an active, governed support access session for this firm.')
            ->schema([
                UnorderedList::make(function () use ($admin, $firm, $connection, $readService): array {
                    $rows = $readService->conflictsForConnection($admin, $firm, $connection->id)
                        ->map(fn (array $conflict): string => sprintf(
                            '%s / %s — %s%s — detected %s%s',
                            $conflict['conflict_type'] ?? '—',
                            $conflict['resource_type'] ?? '—',
                            $conflict['status'] ?? '—',
                            $conflict['requires_manual_review'] ? ' (requires manual review)' : '',
                            optional($conflict['detected_at'])->toDayDateTimeString() ?? '—',
                            filled($conflict['resolution_note'] ?? null) ? " — note: {$conflict['resolution_note']}" : '',
                        ));

                    return $rows->isEmpty() ? ['No conflicts recorded for this connection.'] : $rows->all();
                }),
            ])
            ->collapsible();
    }

    /**
     * Gated on top of the coarse assertCanAccessOversight() check
     * (already enforced by retentionConfigSummary() itself) by
     * PlatformStaffAccessPolicyService::canAccessSecurityLogs()
     * directly (frozen design §11/§12 — no new policy method), re-
     * checked fresh inside this closure on every render, never trusted
     * from mount()-time alone.
     *
     * Security review Finding 3: retentionConfigSummary() itself now
     * ALSO asserts canAccessSecurityLogs() internally (the authoritative
     * gate). Kept here too as belt-and-suspenders for the same graceful-
     * denial-message reason documented on usageSection() above.
     */
    private function retentionSection(PlatformAdmin $admin, IntegrationPlatformOversightReadService $readService): Section
    {
        return Section::make('Retention')
            ->description('Global, non-firm-specific configured retention windows (frozen design §7). This is not a claim that integration retention is legal-hold-safe.')
            ->schema([
                UnorderedList::make(function () use ($admin, $readService): array {
                    $decision = app(PlatformStaffAccessPolicyService::class)->canAccessSecurityLogs($admin);

                    if (! $decision->allowed) {
                        return [$decision->reason ?? 'You are not permitted to view retention configuration.'];
                    }

                    return collect($readService->retentionConfigSummary($admin))
                        ->map(fn (mixed $value, string $key): string => sprintf('%s: %s', str_replace('_', ' ', $key), $value ?? 'not configured'))
                        ->values()
                        ->all();
                }),
            ])
            ->collapsible()
            ->collapsed();
    }

    /**
     * Gated on top of the coarse assertCanAccessFirm() check (already
     * enforced by sanitizedAuditHistoryForFirm() via
     * readWithinFirmAccess()) by
     * PlatformStaffAccessPolicyService::canAccessSecurityLogs() directly
     * (frozen design §11/§12 — the exact-fit match per 11C §4.11 — no
     * new policy method), re-checked fresh inside this closure on every
     * render, never trusted from mount()-time alone.
     *
     * Security review Finding 3: sanitizedAuditHistoryForFirm() itself
     * now ALSO asserts canAccessSecurityLogs() internally (the
     * authoritative gate). Kept here too as belt-and-suspenders for the
     * same graceful-denial-message reason documented on usageSection()
     * above.
     */
    private function auditHistorySection(PlatformAdmin $admin, Firm $firm, IntegrationPlatformOversightReadService $readService): Section
    {
        return Section::make('Sanitized Audit History')
            ->schema([
                UnorderedList::make(function () use ($admin, $firm, $readService): array {
                    $decision = app(PlatformStaffAccessPolicyService::class)->canAccessSecurityLogs($admin);

                    if (! $decision->allowed) {
                        return [$decision->reason ?? 'You are not permitted to view audit history for this firm.'];
                    }

                    $rows = $readService->sanitizedAuditHistoryForFirm($admin, $firm)
                        ->map(fn (array $event): string => sprintf(
                            '%s — %s (%s) at %s',
                            $event['event_type'] ?? '—',
                            $event['source'] ?? '—',
                            $event['actor_type'] ?? '—',
                            optional($event['occurred_at'])->toDayDateTimeString() ?? '—',
                        ));

                    return $rows->isEmpty() ? ['No integration-related audit history recorded for this firm yet.'] : $rows->all();
                }),
            ])
            ->collapsible()
            ->collapsed();
    }
}
