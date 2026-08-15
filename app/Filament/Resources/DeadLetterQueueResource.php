<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\RequeueDeadLetterQueueEventAction;
use App\Filament\Resources\DeadLetterQueueResource\Pages\ListDeadLetterQueueEvents;
use App\Filament\Resources\DeadLetterQueueResource\Pages\ViewDeadLetterQueueEvent;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * DeadLetterQueueResource — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Global, cross-firm oversight
 * of dead-lettered `integration_outbox_events` rows (status =
 * dead_lettered) — there is no separate, dedicated DLQ model in this
 * codebase; the dead-letter queue IS `integration_outbox_events` in its
 * terminal `dead_lettered` state (confirmed directly against
 * OutboxEventStatus/IntegrationOutboxEvent — see
 * PlatformIntegrationCrossFirmDirectoryService's own docblock).
 *
 * The "global retention flag" is
 * `config('integrations.outbox.dead_lettered_retention_days')` —
 * displayed READ-ONLY via the existing, already-reviewed
 * IntegrationPlatformOversightReadService::retentionConfigSummary()
 * (Checkpoint 11), never a new read path. No mutation control exists for
 * it because no dedicated, separately-authorized backend method for
 * changing it exists anywhere in this codebase — per this phase's own
 * explicit instruction, none is invented here.
 *
 * The one mutating action (Requeue) reuses
 * PlatformFirmIntegrationBoundedAccessService::requeueOutboxEvent() — the
 * already-wired, already-audited Checkpoint 11 backend method — via the
 * new RequeueDeadLetterQueueEventAction.
 */
class DeadLetterQueueResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = IntegrationOutboxEvent::class;

    protected static ?string $slug = 'dead-letter-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Dead-Letter Queue';

    /**
     * Operator-facing labels (§18): the underlying model is
     * IntegrationOutboxEvent, so Filament would otherwise render
     * "Integration Outbox Event(s)" in the breadcrumb/detail heading
     * while the navigation says "Dead-Letter Queue" — two names for one
     * screen. These rows are specifically outbox events in their
     * terminal dead_lettered state, so "Dead-Lettered Event" is the
     * honest singular.
     */
    protected static ?string $modelLabel = 'Dead-Lettered Event';

    protected static ?string $pluralModelLabel = 'Dead-Lettered Events';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 22;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];

                $rows = app(PlatformIntegrationCrossFirmDirectoryService::class)->listDeadLetterQueue($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'provider_code' => $filters['provider_code']['value'] ?? null,
                    'from' => $filters['date_range']['from'] ?? null,
                    'to' => $filters['date_range']['to'] ?? null,
                ]);

                return $rows->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('provider_code')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationProvider::query()->orderBy('display_name')->pluck('display_name', 'code')->all()),
                Filter::make('date_range')
                    ->label('Dead-lettered between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                // Canonical provider label from the code, falling back to
                // the row's own denormalised display name and finally to
                // an explicit "not recorded" — never a bare dash. Both
                // fields are nullable here because an outbox event can
                // outlive the connection that produced it.
                TextColumn::make('provider')
                    ->label('Provider')
                    ->state(function (array $record): string {
                        $code = $record['provider_code'] ?? null;

                        if (filled($code)) {
                            return IntegrationDisplay::labelForProviderCode((string) $code);
                        }

                        return IntegrationDisplay::orAbsent($record['provider_display_name'] ?? null, 'Provider not recorded');
                    }),
                TextColumn::make('connection_label')
                    ->label('Connection')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Connection removed')),
                TextColumn::make('original_event_type')->label('Original Event Type'),
                TextColumn::make('failure_category')
                    ->label('Failure Reason')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Not classified')),
                TextColumn::make('dead_lettered_at')->label('Dead-Lettered At')->dateTime()->sortable(),
                TextColumn::make('age')
                    ->label('Age')
                    ->state(fn (array $record): string => filled($record['dead_lettered_at'] ?? null)
                        ? Carbon::parse($record['dead_lettered_at'])->diffForHumans(syntax: true)
                        : IntegrationDisplay::UNKNOWN)
                    ->toggleable(isToggledHiddenByDefault: true),
                // RETENTION (§80): computed from the ACTUAL configured
                // retention window, never a hardcoded 90 days. When the
                // acting admin cannot read the retention config, this
                // says so rather than inventing a date — a wrong deletion
                // date on a forensic record is worse than no date.
                TextColumn::make('retention_expires_at')
                    ->label('Retention Expires')
                    ->state(function (array $record): string {
                        $days = self::currentRetentionDays();

                        if ($days === null) {
                            return 'Retention window unavailable';
                        }

                        if (! filled($record['dead_lettered_at'] ?? null)) {
                            return IntegrationDisplay::UNKNOWN;
                        }

                        $expiresAt = Carbon::parse($record['dead_lettered_at'])->addDays($days);
                        $remaining = (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false);

                        if ($remaining < 0) {
                            return $expiresAt->toDayDateTimeString().' (past retention)';
                        }

                        return $expiresAt->toDayDateTimeString()." ({$remaining} day(s) remaining)";
                    })
                    ->wrap(),
                TextColumn::make('requeue_count')->label('Retry Count')->alignEnd(),
                TextColumn::make('max_requeues')->label('Retry Limit')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                RequeueDeadLetterQueueEventAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewDeadLetterQueueEvent::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No dead-lettered events found')
            ->defaultSort('dead_lettered_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeadLetterQueueEvents::route('/'),
            'view' => ViewDeadLetterQueueEvent::route('/{firmUuid}/{id}'),
        ];
    }

    /**
     * Retention window for the CURRENTLY acting admin, memoized for the
     * render so a 100-row table does not re-resolve the read service —
     * and its authorization gate — once per row. Returns null (rendered
     * as "Retention window unavailable") when the config cannot be read,
     * never a fabricated default.
     */
    private static function currentRetentionDays(): ?int
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return null;
        }

        $memoKey = self::class.'@retentionDays:'.$admin->id;

        if (app()->bound($memoKey)) {
            /** @var ?int $memoized */
            $memoized = app($memoKey);

            return $memoized;
        }

        $days = self::deadLetteredRetentionDays($admin);
        app()->instance($memoKey, $days);

        return $days;
    }

    /**
     * Read-only global retention policy display (see this class's own
     * docblock) — reuses the existing, already-authorized
     * IntegrationPlatformOversightReadService::retentionConfigSummary()
     * rather than a new read path. Returns null (rather than throwing)
     * when the acting admin is not permitted to view it, so callers can
     * render a graceful denial instead of an unhandled error.
     */
    public static function deadLetteredRetentionDays(PlatformAdmin $admin): ?int
    {
        try {
            $summary = app(IntegrationPlatformOversightReadService::class)->retentionConfigSummary($admin);
        } catch (RuntimeException) {
            return null;
        }

        $value = $summary['outbox_dead_lettered_retention_days'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
