<?php

namespace App\Enums;

/**
 * AiToolActionStatus — ai_tool_actions.status. `Blocked` covers both
 * an entitlement/mode block and a PromptInjectionResistanceService
 * rejection (an adversarial instruction found in untrusted
 * document-derived text) — the distinguishing detail lives in
 * ai_tool_actions.output_snapshot_json, not in a third status value.
 */
enum AiToolActionStatus: string
{
    case Executed = 'executed';
    case Blocked = 'blocked';
}
