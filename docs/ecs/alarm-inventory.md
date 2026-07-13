# Alarm Inventory

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

Mirrors `infrastructure/ecs/modules/cloudwatch_alarms/main.tf` exactly — keep both in sync. All alarms notify `var.alarm_sns_topic_arn` (both `ALARM` and `OK` transitions); **this branch does not create the SNS topic or subscribe anyone to it** — who gets paged is an operational/on-call decision for the owning team, tracked as a required input in [staging-readiness-report.md](staging-readiness-report.md).

## Always-on (AWS-native metrics, no code dependency)

| Alarm | Metric | Threshold | Evaluation | Rationale |
|---|---|---|---|---|
| `alb-5xx` | `AWS/ApplicationELB HTTPCode_Target_5XX_Count` (Sum) | > 10/min | 3 consecutive minutes | Sustained upstream error rate, not a single blip. |
| `alb-latency-p90` | `AWS/ApplicationELB TargetResponseTime` (p90) | > 2s | 3 consecutive minutes | Degraded responsiveness across the fleet, not one slow request. |
| `target-unhealthy` | `AWS/ApplicationELB UnHealthyHostCount` (Max) | > 0 | 2 consecutive minutes | Any web task failing `/readyz` — see [container-architecture.md](container-architecture.md). |
| `<role>-running-count-low` (web / general worker / critical worker) | `ECS/ContainerInsights RunningTaskCount` (Avg) | < 1 | 3 consecutive minutes, **missing data treated as breaching** | Crash loop or failed deployment — a role with zero running tasks is never acceptable, so "no data at all" is alarmed just as hard as "0." |
| `<role>-cpu-high` (web / general worker / critical worker) | `AWS/ECS CPUUtilization` (Avg) | > 85% | 5 consecutive minutes | Approaching the task's CPU limit — a leading indicator before throttling/latency shows up elsewhere. |
| `rds-cpu-high` | `AWS/RDS CPUUtilization` (Avg) | > 80% | 5 consecutive minutes | |
| `rds-storage-low` | `AWS/RDS FreeStorageSpace` (Min) | < 5 GiB | 1 period (5 min) | Storage exhaustion is urgent and doesn't need multiple confirming periods. |
| `rds-connections-high` | `AWS/RDS DatabaseConnections` (Avg) | > 80 | 3 consecutive minutes | Placeholder threshold — must be tuned against the actual instance class's `max_connections` once one is chosen (see [infrastructure-architecture.md](infrastructure-architecture.md)); flagged as "Ready with configuration" in the readiness report. |
| `redis-memory-high` | `AWS/ElastiCache DatabaseMemoryUsagePercentage` (Avg) | > 80% | 3 consecutive minutes | |
| `redis-connections-high` | `AWS/ElastiCache CurrConnections` (Avg) | > 500 | 3 consecutive minutes | Placeholder threshold — tune against `cache.t4g.micro`'s real connection ceiling under staging load. |

## Deployment failure

**Not a separate CloudWatch alarm in this design** — ECS's own deployment circuit breaker (`enable_deployment_circuit_breaker = true` on every service in `modules/ecs_service`) automatically rolls back a deployment that fails to reach a steady state, and that rollback itself surfaces as the `<role>-running-count-low` / `target-unhealthy` alarms above firing during the failed window, plus an ECS service event (visible in the ECS console/API, not currently piped to a dedicated CloudWatch alarm — an EventBridge rule on `ECS Deployment State Change` events forwarding to the same SNS topic is a natural follow-up, not built in this branch to keep scope bounded to what the mission's explicit deliverable list asks for).

## Gated behind `enable_custom_metric_alarms` (default `false`)

These depend on an application-level CloudWatch `PutMetricData` call that does not exist yet — see [observability.md](observability.md) "Custom application metrics." The IAM grant and alarm definitions are prepared; the metric emission is not, so these stay off by default rather than alarming permanently on missing data for a metric that will never arrive until that code exists.

| Alarm | Metric (namespace `FirmsBase`) | Threshold | Rationale |
|---|---|---|---|
| `queue-depth-high` | `QueuePendingJobs` (Avg) | > 500 | Matches `QueueHealthService::isHealthy()`'s existing `maxPendingCount` default (`app/Services/QueueHealthService.php:54`) — the alarm threshold is deliberately kept identical to the app's own internal health-check threshold, not an independently invented number. |
| `oldest-queued-job-age-high` | `OldestPendingJobAgeSeconds` (Max) | > 900 (15 min) | Matches `QueueHealthService::isHealthy()`'s `maxOldestPendingAgeSeconds` default. |
| `failed-jobs-high` | `FailedJobsCount` (Max) | > 50 | Matches `QueueHealthService::isHealthy()`'s `maxFailedCount` default. |
| `scheduler-heartbeat-missing` | `SchedulerHeartbeatAgeSeconds` (Max) | > 300, **missing data treated as breaching** | Matches `SchedulerHealthService::isHealthy()`'s `maxAgeSeconds` default (`app/Services/SchedulerHealthService.php:38`). Missing-data-as-breaching mirrors the running-count-low alarms' reasoning: no heartbeat data at all is itself the failure mode this alarm exists to catch. |

## Explicitly requested by the mission but not separately built

- **Webhook failures, payment failures, backup failures, security events**: no application code exists yet that would emit a distinguishable metric for any of these (`WebhookDispatchJob`'s transport is fake, `StripeGateway` has no real implementation, `BackupRestoreDrillRunner` has no real implementation — see [ec2-dependency-audit.md](ec2-dependency-audit.md) §§6/12/13/16). Alarming on a metric with no source is worse than no alarm (guaranteed permanent `INSUFFICIENT_DATA`, training the on-call to ignore it). These are tracked as **deferred** in [staging-readiness-report.md](staging-readiness-report.md), to be added alongside the real feature work that makes each one meaningful.
- **S3 access errors**: no code accesses S3 yet (see [storage-readiness.md](storage-readiness.md)) — same reasoning.
- **Security events**: `app/Services/TenantIsolationAnomalyService.php` and the `health_checks` table's `TenantIsolationAnomalies` check type already exist and are tenant-security-relevant, but wiring a CloudWatch alarm to that specific signal would mean this infra branch reaching into and interpreting RLS/tenant-isolation-owned application state — exactly the kind of cross-boundary change the mission asks to be flagged for separate review rather than made here. Flagged, not built.
