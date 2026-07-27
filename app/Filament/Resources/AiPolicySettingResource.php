<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\EditAiPolicySettingValueAction;
use App\Filament\Resources\AiPolicySettingResource\Pages\ListAiPolicySettings;
use App\Filament\Resources\AiPolicySettingResource\Pages\ViewAiPolicySetting;
use App\Models\AiPolicySetting;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AiPolicySettingResource ("AI Policy Settings") — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Configuration" category). The honest,
 * narrowly-scoped relabeling of "Platform Settings": this manages ONLY
 * the real `ai_policy_settings` table (platform-wide AI guardrails/
 * defaults — approved decision #6). General "Platform Settings" (site
 * name, defaults, etc.) has no backing store anywhere in this codebase
 * and is NOT built here — there is nothing to manage; see this phase's
 * own architecture investigation, Open Decision 4.
 *
 * Unlike every other resource this phase builds, this one is a REAL
 * Eloquent-model-backed table (standard ->query(), no array-records()
 * closure, no per-firm loop) — AiPolicySetting is Global/no-RLS, so no
 * cross-firm-read workaround of any kind is needed.
 *
 * value_json is rendered as formatted, escaped JSON text — never raw/
 * unescaped markup: AI policy config is not expected to contain
 * secrets, but it is still rendered defensively (Filament's
 * TextColumn/TextEntry escape by default; no raw-HTML-rendering method
 * is invoked anywhere in this file).
 */
class AiPolicySettingResource extends Resource
{
    protected static ?string $model = AiPolicySetting::class;

    protected static ?string $slug = 'ai-policy-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'AI Policy Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 71;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessAiPolicySettings($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(AiPolicySetting::query())
            ->columns([
                TextColumn::make('key')->label('Key')->searchable(),
                TextColumn::make('value_json')
                    ->label('Value')
                    ->formatStateUsing(fn (mixed $state): string => Str::limit(json_encode($state, JSON_UNESCAPED_SLASHES) ?: '', 80))
                    ->wrap(),
                TextColumn::make('updated_at')->label('Last updated')->dateTime(),
            ])
            ->recordActions([
                EditAiPolicySettingValueAction::make(),
            ])
            ->recordUrl(fn (AiPolicySetting $record): string => ViewAiPolicySetting::getUrl(['record' => $record->getKey()]))
            ->emptyStateHeading('No AI policy settings configured yet')
            ->defaultSort('key')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiPolicySettings::route('/'),
            'view' => ViewAiPolicySetting::route('/{record}'),
        ];
    }
}
