<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Filament\Actions\Platform\ArchiveNotificationTemplateAction;
use App\Filament\Actions\Platform\CreateFirmOverrideNotificationTemplateAction;
use App\Filament\Actions\Platform\CreateGlobalDefaultNotificationTemplateAction;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplateResource\Pages\ViewNotificationTemplate;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Services\PlatformNotificationTemplateDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * NotificationTemplateResource ("Notification Templates") — Phase 4
 * (FirmsVault Platform Admin Control Center, "Configuration" category).
 * The honest relabeling of "Email Templates": the backend
 * (`NotificationTemplate`) is channel-agnostic across all four
 * `ConsentChannel` values (Email/Sms/WhatsApp/Portal), not Email-only —
 * building Email-only now would create near-certain rework the moment
 * SMS/WhatsApp/Portal templates need admin management, and the backend
 * cost of supporting all four is zero (same table, same service, one
 * extra filter). The Channel filter defaults to no selection (shows
 * every channel); an admin who wants the literal "Email Templates" view
 * can filter to Email.
 *
 * Scope (see PlatformNotificationTemplateDirectoryService's own
 * docblock for the RLS reasoning this follows): with no Firm filter
 * selected, this lists GLOBAL DEFAULT templates only (firm_id IS NULL,
 * a single direct query, no per-firm loop, no context needed —
 * notification_templates' own special dual-policy RLS design makes
 * every global-default row directly readable from a zero-context
 * session). Selecting a Firm filter activates that ONE firm's tenant
 * context and additionally surfaces that firm's own override rows
 * (comparison view: "does this firm override this template"). A full
 * cross-firm "which firms have overridden this template" view (which
 * would need the O(firms) per-firm-loop pattern this phase's other
 * resources use) is deliberately NOT built this pass — scoped out per
 * this phase's own architecture investigation §6, which explicitly
 * flagged this as the lower-priority half of the build.
 *
 * Content/METADATA management only — no real email transport exists
 * anywhere in this codebase (app/Mail does not exist; no Mailable
 * subclass exists outside vendor/; NotificationDispatchService's own
 * docblock: "No real email is ever sent"). No action anywhere on this
 * resource implies a live-send/preview-send capability — disclosed
 * explicitly in the empty-state description below, not merely by
 * omission.
 */
class NotificationTemplateResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = NotificationTemplate::class;

    protected static ?string $slug = 'notification-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Notification Templates';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 72;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessNotificationTemplates($admin)->allowed;
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

                $firmUuid = $filters['firm_uuid']['value'] ?? null;
                $firm = $firmUuid !== null ? Firm::findByUuid($firmUuid) : null;

                try {
                    $rows = app(PlatformNotificationTemplateDirectoryService::class)->list($admin, $firm, [
                        'key' => $filters['key']['value'] ?? null,
                        'channel' => $filters['channel']['value'] ?? null,
                        'status' => $filters['status']['value'] ?? null,
                    ]);
                } catch (RuntimeException) {
                    return collect();
                }

                return $rows->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm (shows overrides + globals)')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                Filter::make('key')
                    ->schema([
                        TextInput::make('value')->label('Key contains'),
                    ]),
                SelectFilter::make('channel')
                    ->options(collect(ConsentChannel::cases())
                        ->mapWithKeys(fn (ConsentChannel $channel): array => [$channel->value => Str::headline($channel->value)])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(NotificationTemplateStatus::cases())
                        ->mapWithKeys(fn (NotificationTemplateStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('key')->label('Key')->searchable(),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('language')->label('Lang'),
                IconColumn::make('is_global_default')->label('Global default')->boolean(),
                TextColumn::make('firm_name')->label('Firm')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        NotificationTemplateStatus::Active->value => 'success',
                        NotificationTemplateStatus::Archived->value => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('subject')->label('Subject')->placeholder('—')->limit(40),
                TextColumn::make('updated_at')->label('Last updated')->dateTime(),
            ])
            ->headerActions([
                CreateGlobalDefaultNotificationTemplateAction::make(),
                CreateFirmOverrideNotificationTemplateAction::make(),
            ])
            ->recordActions([
                ArchiveNotificationTemplateAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewNotificationTemplate::getUrl([
                        'firmUuid' => $record['firm_id'] === null ? 'global' : Firm::query()->find($record['firm_id'])?->uuid,
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No notification templates found')
            ->emptyStateDescription('This console manages template content/metadata only — no real email transport exists anywhere in this codebase, and no action here sends anything.')
            ->defaultSort('updated_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'view' => ViewNotificationTemplate::route('/{firmUuid}/{id}'),
        ];
    }
}
