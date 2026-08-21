<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformAutomationOversightService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PlatformAutomationOversightPage — Mission 7 ("Super Admin Operational
 * Completion"), items 7.2/7.3. Read-only, cross-firm oversight over two
 * previously-invisible-to-platform-staff surfaces of the Event-Driven
 * Automation Engine:
 *
 *  1. (7.2) Automation rules across every firm — `AutomationRuleResource`/
 *     `AutomationActionExecutionResource` exist only in the Firm panel
 *     (confirmed: zero admin-panel equivalent existed before this page).
 *     The main table below shows one row per AutomationRule: firm, rule
 *     name, event type, last execution status, failed-action count.
 *  2. (7.3) Dead-lettered domain events — `DomainEvent.processing_status`/
 *     `dead_lettered_at` had zero admin oversight anywhere
 *     (`DeadLetterQueueResource` is scoped to the unrelated
 *     `IntegrationOutboxEvent` model, not `DomainEvent` — confirmed by
 *     reading that resource directly). The "Domain Event Dead Letters"
 *     section below lists every `domain_events` row with
 *     `processing_status = dead_lettered`.
 *
 * Both data sets are backed exclusively by `PlatformAutomationOversightService`
 * — see that class's own docblock for the full FORCE-RLS/per-firm-loop
 * architectural rationale this page never duplicates.
 *
 * Two independent cross-firm tables cannot both be genuine, paginated
 * Filament `Table` instances on one Livewire page component
 * (`InteractsWithTable` binds exactly one `table()` per class) — the
 * main automation-rules table uses the real, paginated, filterable
 * `->records()` table below (mirrors `FirmUserResource`'s established
 * array-row-backed table shape); the dead-letter list is a second,
 * bounded (most-recent 50), read-only `UnorderedList` section, the same
 * "second data set via a plain list section, not a second Table" shape
 * `PlatformSecurityDashboardPage` already established for its own
 * MFA-gap/role-change sections alongside its one real table.
 *
 * Read-only throughout: no requeue/retry/force-execute action is
 * registered anywhere on this page. Per this mission's own research,
 * there is no safe existing service method for either action, and
 * inventing new domain logic to enable one is explicitly out of scope.
 */
class PlatformAutomationOversightPage extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Bounds the dead-letter section below — never an unbounded render
     * of the whole cross-firm dead-letter backlog.
     */
    private const DEAD_LETTER_DISPLAY_LIMIT = 50;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBoltSlash;

    protected static ?string $navigationLabel = 'Automation Oversight';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Automation Oversight';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Automation Rules')
                ->description('One row per AutomationRule across every firm — firm, rule name, event type, last execution status, and failed-action count. Read-only.')
                ->schema([EmbeddedTable::make()]),
            $this->deadLetterSection(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];
                $firmUuid = $filters['firm_uuid']['value'] ?? null;
                $enabled = $filters['enabled']['value'] ?? null;

                // Narrow the per-firm loop to exactly one firm when a
                // firm filter is applied — the one available
                // optimization against PlatformAutomationOversightService's
                // otherwise O(firm count) read; mirrors
                // FirmUserResource/ConnectionResource's identical
                // $onlyFirmId narrowing.
                $onlyFirmId = null;

                if (filled($firmUuid)) {
                    $onlyFirmId = Firm::query()->where('uuid', $firmUuid)->value('id');
                }

                try {
                    $rows = app(PlatformAutomationOversightService::class)->listAutomationRules($admin, $onlyFirmId);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return collect();
                }

                return $rows
                    ->when($enabled !== null && $enabled !== '', fn (Collection $r): Collection => $r->where('enabled', (bool) $enabled))
                    ->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                TernaryFilter::make('enabled'),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('rule_name')->label('Rule')->searchable(),
                TextColumn::make('event_type')
                    ->label('Event type')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('last_execution_status')
                    ->label('Last execution status')
                    ->badge()
                    ->placeholder('Never executed')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_execution_at')->label('Last execution')->dateTime()->placeholder('—'),
                TextColumn::make('failed_action_count')
                    ->label('Failed actions')
                    ->alignEnd()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
            ])
            ->emptyStateHeading('No automation rules found')
            // Disables Filament's default row-click resolution against
            // this array-shaped table — mirrors FirmUserResource's
            // identical ->recordAction(null)->recordUrl(null) combination
            // for the same reason (a Model-typed default closure would
            // crash against these array rows). No drill-down page exists
            // for a single rule in this admin panel — this page is
            // list-only oversight.
            ->recordAction(null)
            ->recordUrl(null)
            ->defaultSort('firm_name')
            ->paginated([25, 50, 100]);
    }

    private function deadLetterSection(): Section
    {
        return Section::make('Domain Event Dead Letters')
            ->description('Every domain_events row with processing_status = dead_lettered, across every firm, most-recent-first (capped at '.self::DEAD_LETTER_DISPLAY_LIMIT.'). Read-only — no requeue/force-execute action exists; see this page\'s own docblock.')
            ->collapsible()
            ->schema([
                UnorderedList::make(function (): array {
                    $admin = Auth::guard('platform_admin')->user();

                    if (! $admin instanceof PlatformAdmin) {
                        return ['You are not signed in as a platform admin.'];
                    }

                    try {
                        $rows = app(PlatformAutomationOversightService::class)->listDeadLetteredDomainEvents($admin);
                    } catch (RuntimeException $e) {
                        return [$e->getMessage()];
                    }

                    if ($rows->isEmpty()) {
                        return ['No dead-lettered domain events.'];
                    }

                    return $rows
                        ->sortByDesc('dead_lettered_at')
                        ->take(self::DEAD_LETTER_DISPLAY_LIMIT)
                        ->map(function (array $row): string {
                            $eventType = $row['event_type'] !== null ? Str::headline($row['event_type']) : 'Unknown event';
                            $deadLetteredAt = $row['dead_lettered_at']?->toDayDateTimeString() ?? '—';
                            $lastError = filled($row['last_error']) ? " — {$row['last_error']}" : '';

                            return sprintf(
                                '%s — %s (attempts: %d/%d, dead-lettered at %s)%s',
                                $row['firm_name'],
                                $eventType,
                                $row['attempts'] ?? 0,
                                $row['max_attempts'] ?? 0,
                                $deadLetteredAt,
                                $lastError,
                            );
                        })
                        ->all();
                }),
            ]);
    }
}
