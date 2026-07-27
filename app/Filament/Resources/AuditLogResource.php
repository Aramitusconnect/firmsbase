<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PlatformTimelineEventDirectoryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * AuditLogResource — Phase 4 (FirmsVault Platform Admin Control Center,
 * "Operations, Governance, Support, and Configuration"), Governance
 * category. Cross-firm, read-only List+View over `timeline_events` — the
 * BROAD firm-business-activity trail (matter opening, payments, document
 * chases, key destruction, invoice drafting, conflict checks, webhook
 * replays, and 20+ other call sites). Deliberately NOT the same thing as
 * Phase 1's Security Dashboard (`security_events` /
 * PlatformSecurityDashboardService::recentSecurityEvents(), a NARROWER
 * platform-admin-action-only auditing system) — see
 * PlatformTimelineEventDirectoryService's own docblock for the full
 * disambiguation.
 *
 * Read-only by design: TimelineEventRecorder has no update/delete
 * method, and TimelineEvent itself blocks `updating`/`deleting` at the
 * model layer (append-only). No mutating action is registered anywhere
 * in this class.
 *
 * FORCE RLS, single-clause policy with no null-firm_id-visible branch —
 * queried exclusively via PlatformTimelineEventDirectoryService's
 * per-firm-loop pattern, mirroring DeadLetterQueueResource's own
 * ->records() closure shape for a FORCE-RLS cross-firm table.
 */
class AuditLogResource extends Resource
{
    /**
     * Real model set here for framework label metadata only (see
     * SyncFailureResource's own docblock for why) — canAccess() below
     * never calls parent::canAccess(), and the table never binds an
     * Eloquent query directly against this model.
     */
    protected static ?string $model = TimelineEvent::class;

    protected static ?string $slug = 'audit-logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed;
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

                return app(PlatformTimelineEventDirectoryService::class)->list($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'event_type' => $filters['event_type']['value'] ?? null,
                    'from' => $filters['date_range']['from'] ?? null,
                    'to' => $filters['date_range']['to'] ?? null,
                ])->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                Filter::make('event_type')
                    ->schema([
                        TextInput::make('value')->label('Event type contains'),
                    ]),
                Filter::make('date_range')
                    ->label('Occurred between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('event_type')->label('Event type')->searchable(),
                TextColumn::make('subject_type')->label('Subject type')->placeholder('—'),
                TextColumn::make('subject_id')->label('Subject id')->placeholder('—'),
                TextColumn::make('actor_type')->label('Actor type')->placeholder('—'),
                TextColumn::make('actor_id')->label('Actor id')->placeholder('—'),
                TextColumn::make('occurred_at')->label('Occurred at')->dateTime(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewAuditLog::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No audit log events found')
            ->emptyStateDescription('This is the general firm-business-activity trail (timeline_events) — matter openings, payments, document chases, and similar. It is distinct from the Security Dashboard\'s platform-admin action log.')
            ->defaultSort('occurred_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{firmUuid}/{id}'),
        ];
    }
}
