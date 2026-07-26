<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\RequeueDeadLetterQueueEventAction;
use App\Filament\Resources\DeadLetterQueueResource\Pages\ListDeadLetterQueueEvents;
use App\Filament\Resources\DeadLetterQueueResource\Pages\ViewDeadLetterQueueEvent;
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
                TextColumn::make('provider_display_name')->label('Provider')->placeholder('—'),
                TextColumn::make('connection_label')->label('Connection')->placeholder('—'),
                TextColumn::make('original_event_type')->label('Original event type'),
                TextColumn::make('failure_category')->label('Failure reason')->placeholder('—'),
                TextColumn::make('dead_lettered_at')->label('Dead-lettered at')->dateTime(),
                TextColumn::make('requeue_count')->label('Retry count')->alignEnd(),
                TextColumn::make('max_requeues')->label('Retry limit')->alignEnd(),
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
