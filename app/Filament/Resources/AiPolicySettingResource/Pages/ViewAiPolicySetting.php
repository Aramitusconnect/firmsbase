<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicySettingResource\Pages;

use App\Filament\Actions\Platform\EditAiPolicySettingValueAction;
use App\Filament\Resources\AiPolicySettingResource;
use App\Models\AiPolicySetting;
use App\Models\SecurityEvent;
use App\Services\Configuration\AiPolicyDefinitionRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewAiPolicySetting — a standard Eloquent-backed ViewRecord (see
 * AiPolicySettingResource's own docblock for why this table needs no
 * cross-firm-read workaround, unlike this phase's other resources).
 * value_json is rendered as formatted, escaped JSON text via a plain
 * TextEntry — no raw-HTML-rendering method is invoked, never
 * unescaped output.
 *
 * Configuration Control Plane additions:
 *
 *   TYPED DEFINITION. What this key IS — the service that reads it, its
 *   real type, what an absent row means, and whether it is governed
 *   elsewhere. A key with no consumer says so plainly rather than
 *   sitting in a governance console implying it controls something
 *   (mission sections 51/62/100).
 *
 *   HISTORY (mission section 61). Read from the EXISTING `security_events`
 *   audit trail written by AiPolicySettingService — no second history
 *   store is introduced. `ai_policy_settings` keeps no previous values
 *   of its own (it is a plain upsert on a unique key), so the honest
 *   position is stated: the trail records WHO changed WHAT and WHEN,
 *   with the reason where one was required, but not the prior value.
 *   That limitation is disclosed rather than papered over with a
 *   reconstructed "previous value" the data cannot support.
 */
class ViewAiPolicySetting extends ViewRecord
{
    protected static string $resource = AiPolicySettingResource::class;

    /**
     * Bounded — this is a detail page, not an audit export.
     */
    private const HISTORY_LIMIT = 50;

    protected function getHeaderActions(): array
    {
        return [
            EditAiPolicySettingValueAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('AI Policy Setting')
                ->columns(1)
                ->schema([
                    TextEntry::make('key'),
                    TextEntry::make('value_json')
                        ->label('Value')
                        ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                    TextEntry::make('meaning')
                        ->label('What this value means')
                        ->state(fn (AiPolicySetting $record): string => app(AiPolicyDefinitionRegistry::class)
                            ->describeValue($record->key, $record->value_json))
                        ->columnSpanFull(),
                    TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                ]),

            Section::make('Definition')
                ->columns(2)
                ->schema([
                    TextEntry::make('definition_type')
                        ->label('Type')
                        ->state(fn (AiPolicySetting $record): string => app(AiPolicyDefinitionRegistry::class)
                            ->find($record->key)['type'] ?? 'Untyped')
                        ->badge(),
                    TextEntry::make('definition_category')
                        ->label('Category')
                        ->state(fn (AiPolicySetting $record): string => app(AiPolicyDefinitionRegistry::class)
                            ->find($record->key)['category'] ?? 'Uncategorized')
                        ->badge(),
                    TextEntry::make('definition_consumer')
                        ->label('Read by')
                        ->state(fn (AiPolicySetting $record): string => app(AiPolicyDefinitionRegistry::class)
                            ->find($record->key)['consumer'] ?? 'No service in this codebase reads this key.')
                        ->columnSpanFull(),
                    TextEntry::make('definition_absent')
                        ->label('If no row existed')
                        ->state(fn (AiPolicySetting $record): string => app(AiPolicyDefinitionRegistry::class)
                            ->find($record->key)['absent_meaning'] ?? 'Not applicable — nothing reads this key.')
                        ->columnSpanFull(),
                    TextEntry::make('definition_governed')
                        ->label('Managed by')
                        ->state(function (AiPolicySetting $record): string {
                            $definition = app(AiPolicyDefinitionRegistry::class)->find($record->key);

                            if ($definition === null) {
                                return 'Editable here (stored data only — no service reads it).';
                            }

                            return $definition['governed']
                                ? $definition['governed_by'].' — '.$definition['governed_reason']
                                : 'Editable here.';
                        })
                        ->columnSpanFull(),
                    TextEntry::make('definition_scope')
                        ->label('Firm overrides')
                        ->state('Not implemented — ai_policy_settings has no firm_id column, so this table is platform-level only.')
                        ->columnSpanFull(),
                ]),

            Section::make('History')
                ->description('From the existing platform audit trail. This table stores no previous values of its own, so the trail records who changed it and when — not the prior value.')
                ->collapsible()
                ->schema([
                    TextEntry::make('history')
                        ->hiddenLabel()
                        ->state(fn (AiPolicySetting $record): string => self::historyFor($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function historyFor(AiPolicySetting $record): string
    {
        // ai_policy_settings is Global/no-RLS and the audit rows for it
        // are written with a null firm_id, so no tenant context is
        // needed or appropriate here.
        $events = SecurityEvent::query()
            ->where('category', 'ai_policy_settings')
            ->whereIn('event_type', ['ai_policy_setting_created', 'ai_policy_setting_updated'])
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'event_type', 'actor_id', 'actor_type', 'metadata', 'created_at'])
            ->filter(fn (SecurityEvent $event): bool => ($event->metadata['key'] ?? null) === $record->key);

        if ($events->isEmpty()) {
            // A genuine measured empty — audit rows are only written
            // when an actor is supplied, so a seeded or system-written
            // row legitimately has none.
            return 'No recorded changes for this key. Audit rows are written only for actor-attributed changes, '
                .'so a value written by a migration or by system code has no entry here.';
        }

        return $events->map(fn (SecurityEvent $event): string => sprintf(
            '%s — %s by platform admin #%s%s',
            $event->created_at?->toDayDateTimeString() ?? 'Unknown time',
            $event->event_type === 'ai_policy_setting_created' ? 'created' : 'updated',
            $event->actor_id ?? 'unknown',
            isset($event->metadata['reason']) ? ' — reason: "'.$event->metadata['reason'].'"' : '',
        ))->implode("\n");
    }
}
