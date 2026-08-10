<?php

namespace App\Enums;

/**
 * MatterLeverageConfidence — Leverage Ratio Optimizer, item 14. A
 * deterministic, rule-derived classification of how strong a
 * recommendation's own evidence is — never an LLM judgment call. HIGH
 * requires an explicit Firm-configured TaskCategoryRoleExpectation for
 * the exact category involved; MEDIUM is a strong divergence from a
 * Matter Type's own MatterBudgetTemplate staffing expectation; LOW is
 * a system-wide historical pattern with no explicit Firm configuration
 * behind it at all. See LeverageRecommendationService for the exact
 * rule each recommendation type applies.
 */
enum MatterLeverageConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
