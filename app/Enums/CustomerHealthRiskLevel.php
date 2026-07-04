<?php

namespace App\Enums;

enum CustomerHealthRiskLevel: string
{
    case Healthy = 'healthy';
    case AtRisk = 'at_risk';
    case Critical = 'critical';
}
