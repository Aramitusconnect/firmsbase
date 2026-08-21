<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\DomainEventProcessingStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PlatformAutomationOversightService — Mission 7 ("Super Admin
 * Operational Completion"), items 7.2/7.3. The read path behind
 * PlatformAutomationOversightPage: cross-firm, read-only oversight over
 * the Event-Driven Automation Engine's own `automation_rules` /
 * `automation_action_executions` (7.2) and over dead-lettered
 * `domain_events` rows (7.3) — neither has any admin-panel surface
 * today; `AutomationRuleResource`/`AutomationActionExecutionResource`
 * exist only in the Firm panel.
 *
 * Architectural constraint this class exists to satisfy, mirroring
 * `PlatformFirmUserDirectoryService`'s own docblock exactly one level
 * over: `automation_rules`, `automation_executions`,
 * `automation_action_executions`, and `domain_events` all carry
 * permanent FORCE ROW LEVEL SECURITY with no cross-firm-read policy —
 * there is no policy that lets any session read across every firm's
 * rows at once, and (per that service's own docblock, unchanged here)
 * the runtime database role this application connects as is never
 * granted BYPASSRLS or superuser. The only architecturally-sound way to
 * read these tables across every firm is the SAME per-firm loop
 * pattern already approved for this exact problem elsewhere in this
 * codebase: each iteration wrapped in
 * `TenantContextService::runWithFirmContext()`, merged in PHP — never a
 * naive cross-firm query, and never a new BYPASSRLS/superuser carve-out.
 *
 * Known, deliberate performance trade-off (flagged for reviewer
 * attention, same as `PlatformFirmUserDirectoryService`'s own
 * disclosure): this is O(number of firms) queries per call (a small,
 * fixed number of bulk queries per firm — never per-rule/per-execution),
 * not O(1). Acceptable at the platform's current expected scale; would
 * need re-architecting (e.g. a precomputed summary table, mirroring
 * `integration_platform_overview_summaries`) if the firm population
 * grows large enough to make a full per-request scan noticeably slow —
 * explicitly out of this mission's scope, called out here rather than
 * silently addressed.
 *
 * Read-only throughout — no requeue/retry/force-execute method exists
 * on this class. Per this mission's own research: there is no safe
 * existing service method for requeuing a rule's failed actions or
 * forcing a dead-lettered domain event to reprocess, and inventing new
 * domain logic to enable one is explicitly out of this mission's scope.
 */
class PlatformAutomationOversightService
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessOperations($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access operations oversight.');
        }
    }

    /**
     * 7.2 — one row per AutomationRule across every active firm: firm,
     * rule name, event type, last execution status, failed-action count.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listAutomationRules(PlatformAdmin $admin, ?int $onlyFirmId = null): Collection
    {
        $this->assertCanAccess($admin);

        $firms = Firm::query()
            ->when($onlyFirmId !== null, fn ($query) => $query->where('id', $onlyFirmId))
            ->orderBy('name')
            ->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $firmRows = $this->tenantContext->runWithFirmContext($firm, fn (): Collection => $this->rulesForFirm());

            foreach ($firmRows as $row) {
                $rows->push($this->toRuleRow($firm, $row));
            }
        }

        return $rows;
    }

    /**
     * 7.3 — one row per dead-lettered DomainEvent across every active
     * firm: firm, event type, dead-lettered at, failure info.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listDeadLetteredDomainEvents(PlatformAdmin $admin, ?int $onlyFirmId = null): Collection
    {
        $this->assertCanAccess($admin);

        $firms = Firm::query()
            ->when($onlyFirmId !== null, fn ($query) => $query->where('id', $onlyFirmId))
            ->orderBy('name')
            ->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $events = $this->tenantContext->runWithFirmContext($firm, fn () => DomainEvent::query()
                ->where('processing_status', DomainEventProcessingStatus::DeadLettered)
                ->orderByDesc('dead_lettered_at')
                ->get());

            foreach ($events as $event) {
                $rows->push($this->toDeadLetterRow($firm, $event));
            }
        }

        return $rows;
    }

    /**
     * Runs entirely inside the caller's active firm context — a handful
     * of bulk queries bounded by this one firm's own rule/execution
     * counts, never per-rule/per-execution N+1 queries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rulesForFirm(): Collection
    {
        $rules = AutomationRule::query()->orderBy('name')->get();

        if ($rules->isEmpty()) {
            return collect();
        }

        $ruleIds = $rules->pluck('id');

        // Every execution for this firm's rules, most-recent-first — the
        // first row encountered per rule_id group below is therefore
        // that rule's own last execution.
        $executions = AutomationExecution::query()
            ->whereIn('automation_rule_id', $ruleIds)
            ->orderByDesc('created_at')
            ->get(['id', 'automation_rule_id', 'status', 'created_at']);

        $lastExecutionByRuleId = $executions->groupBy('automation_rule_id')->map(fn (Collection $group) => $group->first());

        // Failed-action counts, grouped straight to rule_id via one join
        // — never a second query per rule.
        $failedActionCountByRuleId = AutomationActionExecution::query()
            ->join('automation_executions', 'automation_executions.id', '=', 'automation_action_executions.automation_execution_id')
            ->whereIn('automation_executions.automation_rule_id', $ruleIds)
            ->where('automation_action_executions.status', AutomationActionExecutionStatus::Failed->value)
            ->select('automation_executions.automation_rule_id as rule_id', DB::raw('count(*) as failed_count'))
            ->groupBy('automation_executions.automation_rule_id')
            ->pluck('failed_count', 'rule_id');

        return $rules->map(fn (AutomationRule $rule): array => [
            'rule' => $rule,
            'last_execution' => $lastExecutionByRuleId->get($rule->id),
            'failed_action_count' => (int) ($failedActionCountByRuleId->get($rule->id) ?? 0),
        ])->values();
    }

    /**
     * @param  array{rule: AutomationRule, last_execution: ?AutomationExecution, failed_action_count: int}  $row
     * @return array<string, mixed>
     */
    private function toRuleRow(Firm $firm, array $row): array
    {
        /** @var AutomationRule $rule */
        $rule = $row['rule'];

        /** @var ?AutomationExecution $lastExecution */
        $lastExecution = $row['last_execution'];

        return [
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'event_type' => $rule->event_type?->value,
            'enabled' => $rule->enabled,
            'last_execution_status' => $lastExecution?->status?->value,
            'last_execution_at' => $lastExecution?->created_at,
            'failed_action_count' => $row['failed_action_count'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toDeadLetterRow(Firm $firm, DomainEvent $event): array
    {
        return [
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'event_id' => $event->id,
            'event_type' => $event->event_type?->value,
            'attempts' => $event->attempts,
            'max_attempts' => $event->max_attempts,
            'last_error' => $event->last_error,
            'dead_lettered_at' => $event->dead_lettered_at,
        ];
    }
}
