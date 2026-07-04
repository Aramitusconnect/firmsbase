<?php

namespace App\Enums;

/**
 * HealthCheckType — health_checks.check_type. The 9 monitoring
 * surfaces named verbatim in the master plan's Phase 5 Scope: "web
 * uptime, queue workers, scheduler, failed jobs, storage, email
 * delivery, payment webhooks, document scanning, and tenant isolation
 * anomalies." New check types beyond these 9 register through
 * HealthCheckRegistry with a corresponding new case here (a genuine
 * schema-adjacent addition, unlike ReadinessScorecardComponent, which
 * is intentionally schema-free) — HealthCheckType is closed by design
 * because monitoring surfaces are a deliberate, reviewed list, not an
 * arbitrary per-firm catalog.
 */
enum HealthCheckType: string
{
    case WebUptime = 'web_uptime';
    case QueueWorkers = 'queue_workers';
    case Scheduler = 'scheduler';
    case FailedJobs = 'failed_jobs';
    case Storage = 'storage';
    case EmailDelivery = 'email_delivery';
    case PaymentWebhooks = 'payment_webhooks';
    case DocumentScanning = 'document_scanning';
    case TenantIsolationAnomalies = 'tenant_isolation_anomalies';
}
