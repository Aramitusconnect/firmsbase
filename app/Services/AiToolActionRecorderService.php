<?php

namespace App\Services;

use App\Enums\AiToolActionStatus;
use App\Models\AiToolAction;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;

/**
 * AiToolActionRecorderService — the only writer of ai_tool_actions
 * (project rule 10: every AI tool action is audited). Records one row
 * per tool name in the adapter's response. was_constrained is set
 * whenever PromptInjectionResistanceService detects an injection
 * pattern in the request's document-derived text — for audit
 * visibility, even where the attempt could never have structurally
 * succeeded. status is Blocked
 * whenever the request did not explicitly allow tool actions
 * (allowToolActions = false) but the caller is recording an action
 * anyway (defensive — should never happen given AiProviderResponse's
 * own contract, but recorded rather than silently ignored if it does).
 */
class AiToolActionRecorderService
{
    public function __construct(private readonly PromptInjectionResistanceService $promptInjectionResistance) {}

    /**
     * @return array<AiToolAction>
     */
    public function recordFromResponse(
        Firm $firm,
        ?Matter $matter,
        AiUsageEvent $usageEvent,
        AiPromptRequest $request,
        AiProviderResponse $response,
    ): array {
        $wasFlagged = $this->promptInjectionResistance->evaluate($request);

        $recorded = [];

        foreach ($response->requestedToolActions as $toolName) {
            $status = $request->allowToolActions ? AiToolActionStatus::Executed : AiToolActionStatus::Blocked;

            $recorded[] = AiToolAction::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter?->id,
                'ai_usage_event_id' => $usageEvent->id,
                'tool_name' => $toolName,
                'input_snapshot_json' => ['instruction_text' => $request->instructionText],
                'output_snapshot_json' => ['tool_name' => $toolName],
                'was_constrained' => $wasFlagged || ! $request->allowToolActions,
                'status' => $status,
            ]);
        }

        return $recorded;
    }
}
