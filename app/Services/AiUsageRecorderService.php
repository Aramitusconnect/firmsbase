<?php

namespace App\Services;

use App\Enums\UsageRollupMetric;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;
use Illuminate\Support\Facades\DB;

/**
 * AiUsageRecorderService — the only writer of ai_usage_events
 * (project rule 8: append-only). This is the single orchestration
 * entry point every AI action flows through: mode/entitlement gate
 * (AiModeResolutionService), budget gate (AiBudgetEnforcementService),
 * usage recording, organization-level rollup attribution
 * (UsageRollupService::recordUsage() with UsageRollupMetric::AiTokens
 * — Phase 6, previously unused, built for exactly this), automatic
 * high-risk approval submission (AiApprovalWorkflowService), and tool
 * action auditing (AiToolActionRecorderService).
 *
 * cost_cents is METADATA ONLY in Phase 15 (project rule 24) — computed
 * from a fixed nominal per-token rate plus
 * firm_ai_settings.usage_markup_basis_points, never written to
 * platform_invoices/payments, and never triggers real billing (project
 * rule 23: do not touch platform billing money movement).
 *
 * Budget/period: Phase 15 uses the current calendar month as the
 * enforcement period (firm_ai_settings has no separate period-length
 * column) — documented here rather than left implicit.
 */
class AiUsageRecorderService
{
    /**
     * Nominal, non-billing rate: 1 cent per 100 combined tokens,
     * before markup. Exists only so usage_markup_basis_points has
     * something to multiply for test/reporting purposes.
     */
    private const NOMINAL_CENTS_PER_100_TOKENS = 1;

    public function __construct(
        private readonly AiModeResolutionService $modeResolution,
        private readonly AiBudgetEnforcementService $budgetEnforcement,
        private readonly AiApprovalWorkflowService $approvalWorkflow,
        private readonly AiToolActionRecorderService $toolActionRecorder,
        private readonly UsageRollupService $usageRollupService,
    ) {}

    /**
     * Section 39A-5 (Wave 1, firm_ai_settings checkpoint) — firm_ai_settings
     * now has FORCE ROW LEVEL SECURITY active (see the
     * 2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php
     * migration), and this method is its only real reader
     * (AiBudgetEnforcementService::checkFirmBudget() and
     * computeCostCents() below, both firm_ai_settings readers via
     * Firm::aiSettings()). record() is a single-firm operation from
     * start to finish, so the ENTIRE method body is wrapped in ONE
     * outer runWithFirmContext() call here rather than wrapping each
     * sub-piece separately — per this project's established
     * convention, a nested self-wrap of an inner call would clear an
     * already-active outer caller's context prematurely when this
     * method is invoked from within one.
     *
     * This single wrap is ALREADY comprehensive for the
     * ai_usage_events/ai_approval_requests/ai_approval_events/
     * ai_tool_actions writes performed inside this same method body —
     * a later, separate wave activating FORCE ROW LEVEL SECURITY on
     * those other tables must NOT re-wrap this method again.
     */
    public function record(
        Firm $firm,
        User $user,
        AiPromptRequest $request,
        AiProviderResponse $response,
        ?Matter $matter = null,
    ): AiUsageEvent {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $user, $request, $response, $matter) {
            $this->modeResolution->assertEnabled($firm);
            $this->modeResolution->assertProviderAccess($firm, $request->provider);

            [$periodStartsAt, $periodEndsAt] = $this->currentPeriod();

            $totalTokens = $response->tokensIn + $response->tokensOut;
            $costCents = $this->computeCostCents($firm, $totalTokens);

            $budgetResult = $this->budgetEnforcement->checkFirmBudget(
                $firm,
                $totalTokens,
                $costCents,
                $periodStartsAt,
                $periodEndsAt,
            );

            if (! $budgetResult->allowed()) {
                throw new \RuntimeException($budgetResult->reason ?? 'AI budget/token limit exceeded.');
            }

            $isHighRisk = $request->actionType->isHighRisk();

            $usageEvent = DB::transaction(function () use ($firm, $user, $matter, $request, $response, $costCents, $isHighRisk) {
                return AiUsageEvent::create([
                    'firm_id' => $firm->id,
                    'user_id' => $user->id,
                    'matter_id' => $matter?->id,
                    'ai_mode' => $this->modeResolution->resolve($firm),
                    'provider' => $request->provider,
                    'model' => $request->model,
                    'tokens_in' => $response->tokensIn,
                    'tokens_out' => $response->tokensOut,
                    'cost_cents' => $costCents,
                    'approval_required' => $isHighRisk,
                    'action_type' => $request->actionType,
                ]);
            });

            if ($firm->billingAccount) {
                $this->usageRollupService->recordUsage(
                    $firm->billingAccount,
                    $firm,
                    UsageRollupMetric::AiTokens,
                    $totalTokens,
                    $periodStartsAt,
                    $periodEndsAt,
                );
            }

            if ($isHighRisk) {
                $category = $request->actionType->toApprovalCategory();

                if ($category !== null) {
                    $this->approvalWorkflow->submit(
                        $firm,
                        $user,
                        $usageEvent,
                        $category,
                        $response->outputText,
                        $matter,
                    );
                }
            }

            $this->toolActionRecorder->recordFromResponse($firm, $matter, $usageEvent, $request, $response);

            return $usageEvent;
        });
    }

    private function computeCostCents(Firm $firm, int $totalTokens): int
    {
        $base = (int) round(($totalTokens / 100) * self::NOMINAL_CENTS_PER_100_TOKENS);
        $markupBasisPoints = $firm->aiSettings?->usage_markup_basis_points ?? 0;

        return $base + (int) round($base * ($markupBasisPoints / 10000));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function currentPeriod(): array
    {
        $start = new \DateTimeImmutable('first day of this month 00:00:00');
        $end = new \DateTimeImmutable('last day of this month 23:59:59');

        return [$start, $end];
    }
}
