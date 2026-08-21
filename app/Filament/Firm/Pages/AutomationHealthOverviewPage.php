<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Services\Automation\AutomationAccessPolicyService;
use App\Services\Automation\AutomationObservabilityService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * AutomationHealthOverviewPage — Event-Driven Automation Engine
 * observability gap: AutomationObservabilityService::summary()
 * computes 16 real firm-scoped automation-health metrics (execution
 * counts, action outcome counts, rule enabled/disabled counts,
 * dead-lettered events, average execution duration, oldest queued
 * event, repeatedly-failing rule ids, reminder delivered/blocked
 * counts) but had zero production callers anywhere in the app before
 * this page. A read-only Filament Page (deliberately not a Resource —
 * no single underlying model, only a firm-scoped aggregate), wired
 * directly to that service — nothing here computes a metric itself, it
 * only renders what the service returns, matching
 * AccountingOverviewPage/StaffingLeverageOverviewPage's own established
 * "no calculation logic inside Filament pages" convention.
 *
 * Distinct from three other automation surfaces this page must not
 * duplicate or touch: PlatformAutomationOversightPage (cross-tenant
 * ADMIN oversight, backed by the unrelated
 * PlatformAutomationOversightService), and the Firm-panel
 * AutomationRuleResource/AutomationActionExecutionResource (raw
 * per-record lists, not aggregate summaries).
 *
 * Gated by AutomationAccessPolicyService::canManageRules() — the same
 * ceiling AutomationRuleResource's own AutomationRulePolicy enforces
 * for automation visibility in the Firm panel, reused here rather than
 * inventing a second automation permission surface.
 */
class AutomationHealthOverviewPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Health Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Automation Health Overview';

    public static function canAccess(): bool
    {
        return static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(AutomationAccessPolicyService::class)->canManageRules($firmUser->role);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Executions')
                ->schema([
                    Text::make(fn (): string => $this->metricLine('Total executions', $this->summary()['executions_total'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Matched executions', $this->summary()['executions_matched'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->averageDurationLine())->size(TextSize::Medium),
                    Text::make(fn (): string => $this->oldestQueuedEventLine())->size(TextSize::Medium),
                ]),
            Section::make('Action Outcomes')
                ->schema([
                    Text::make(fn (): string => $this->metricLine('Succeeded', $this->summary()['actions_succeeded'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Failed', $this->summary()['actions_failed'] ?? 0))
                        ->size(TextSize::Medium)
                        ->weight(fn (): FontWeight => ($this->summary()['actions_failed'] ?? 0) > 0 ? FontWeight::Bold : FontWeight::Normal),
                    Text::make(fn (): string => $this->metricLine('Retry scheduled', $this->summary()['actions_retry_scheduled'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Awaiting approval', $this->summary()['actions_awaiting_approval'] ?? 0))->size(TextSize::Medium),
                ]),
            Section::make('Rules')
                ->schema([
                    Text::make(fn (): string => $this->metricLine('Enabled', $this->summary()['rules_enabled'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Disabled', $this->summary()['rules_disabled'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->repeatedlyFailingRulesLine())
                        ->size(TextSize::Medium)
                        ->weight(fn (): FontWeight => ($this->summary()['repeatedly_failing_rule_ids'] ?? []) !== [] ? FontWeight::Bold : FontWeight::Normal),
                ]),
            Section::make('Dead Letters')
                ->schema([
                    Text::make(fn (): string => $this->metricLine('Dead-lettered events', $this->summary()['events_dead_lettered'] ?? 0))
                        ->size(TextSize::Medium)
                        ->weight(fn (): FontWeight => ($this->summary()['events_dead_lettered'] ?? 0) > 0 ? FontWeight::Bold : FontWeight::Normal),
                ]),
            Section::make('Workflow Activity')
                ->schema([
                    Text::make(fn (): string => $this->metricLine('Tasks created', $this->summary()['tasks_created'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Document requests created', $this->summary()['document_requests_created'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Checklist completions', $this->summary()['checklist_completions'] ?? 0))->size(TextSize::Medium),
                ]),
            Section::make('Client Reminders')
                ->schema([
                    Text::make(fn (): string => $this->metricLine('Attempted', $this->summary()['reminders_attempted'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Delivered', $this->summary()['reminders_delivered'] ?? 0))->size(TextSize::Medium),
                    Text::make(fn (): string => $this->metricLine('Blocked (requires review)', $this->summary()['reminders_blocked'] ?? 0))->size(TextSize::Medium),
                ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(AutomationObservabilityService::class)->summary($firmUser->firm);
    }

    private function metricLine(string $label, int $count): string
    {
        return "{$label}: {$count}";
    }

    private function averageDurationLine(): string
    {
        $seconds = $this->summary()['average_execution_duration_seconds'] ?? null;

        if ($seconds === null) {
            return 'Average execution duration: no completed executions yet.';
        }

        return "Average execution duration: {$seconds}s";
    }

    private function oldestQueuedEventLine(): string
    {
        $oldest = $this->summary()['oldest_queued_event_created_at'] ?? null;

        if ($oldest === null) {
            return 'Oldest queued event: none pending.';
        }

        return "Oldest queued event: {$oldest}";
    }

    private function repeatedlyFailingRulesLine(): string
    {
        $ids = $this->summary()['repeatedly_failing_rule_ids'] ?? [];

        if ($ids === []) {
            return 'No rules are repeatedly failing.';
        }

        return 'Repeatedly failing rule(s): '.implode(', ', $ids);
    }
}
