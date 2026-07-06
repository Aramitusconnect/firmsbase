<?php

namespace App\Enums;

/**
 * DeploymentHealthReportMode — deployment_health_checks.reported_via.
 * OfflineReport is used whenever
 * private_enterprise_settings.telemetry_prohibited is true — the
 * health check is still recorded locally, just never transmitted
 * anywhere (project rule 16 / Master Plan §23: "fully offline report
 * fallback where telemetry is prohibited").
 */
enum DeploymentHealthReportMode: string
{
    case Live = 'live';
    case OfflineReport = 'offline_report';
}
