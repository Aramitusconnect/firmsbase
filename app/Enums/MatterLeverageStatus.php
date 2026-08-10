<?php

namespace App\Enums;

/**
 * MatterLeverageStatus — Leverage Ratio Optimizer, item 20. A
 * transparent, explicitly-ruled Matter-level status — deliberately NOT
 * an opaque numeric score (the master spec's own explicit instruction:
 * "avoid creating an arbitrary black-box score... no AI-generated
 * scoring"). See LeverageAnalysisService::status() for the exact,
 * documented rule each value maps to.
 */
enum MatterLeverageStatus: string
{
    case Healthy = 'healthy';
    case Watch = 'watch';
    case Inefficient = 'inefficient';
    case InsufficientData = 'insufficient_data';
}
