<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * OperationalReadinessMappingService — declares the master plan's
 * Section 29 operational requirements (19 keys) and maps each to an
 * EXISTING owning class/model, or the explicit absence of one. Purely
 * declarative — no real backup/restore execution, no real monitoring
 * provider, no real queue/scheduler deployment, no new secret/key
 * system. Reuses GovernanceMappingResult/GovernanceMappingStatus from
 * the Section 25-28 cross-cutting package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository at the time this service was written. Where
 * this package cannot machine-prove a requirement (e.g. no codebase
 * fork), it says so explicitly rather than overclaiming.
 */
class OperationalReadinessMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'backups',
                item_label: 'Backups',
                owning_class: \App\Services\BackupRestoreTestService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'BackupRestoreTestService records backup/restore drill RESULTS; its own docblock states it "never performs a real infrastructure backup/restore." Readiness/bookkeeping only.',
            ),
            new GovernanceMappingResult(
                item_key: 'restore_testing',
                item_label: 'Restore testing',
                owning_class: \App\Services\BackupRestoreTestService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'BackupRestoreTestService::fullyVerified()/runDrill() are real and tested, but FakeBackupRestoreDrillRunner is the only implementation exercised — no real restore path is ever exercised. See the restore_tests_do_not_exercise_real_restore_path gap (Section 28).',
            ),
            new GovernanceMappingResult(
                item_key: 'monitoring',
                item_label: 'Monitoring',
                owning_class: \App\Services\HealthCheckService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'HealthCheckService/HealthCheckRegistry, QueueHealthService, and SchedulerHealthService all perform real, functional checks against real local state (jobs/failed_jobs tables, heartbeat cache). No real external monitoring provider of any kind is integrated.',
            ),
            new GovernanceMappingResult(
                item_key: 'alerting',
                item_label: 'Alerting',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No alerting service, provider integration, or concept of any kind exists anywhere in the repository (confirmed by direct search) — health/status data is recorded but nothing notifies anyone when it degrades.',
            ),
            new GovernanceMappingResult(
                item_key: 'scheduler_health',
                item_label: 'Scheduler health',
                owning_class: \App\Services\SchedulerHealthService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'SchedulerHealthService checks real heartbeat staleness via cache; tests/Feature/QueueHealth/SchedulerHealthServiceTest.php exercises it directly. Implemented as a readiness check (no real scheduler process is deployed in this environment, but the health-check logic itself is real).',
            ),
            new GovernanceMappingResult(
                item_key: 'queue_health',
                item_label: 'Queue health',
                owning_class: \App\Services\QueueHealthService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'QueueHealthService reads the real jobs/failed_jobs tables (pendingJobsCount()/failedJobsCount()/isHealthy()); tests/Feature/QueueHealth/QueueHealthServiceTest.php exercises it directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'log_retention',
                item_label: 'Log retention',
                owning_class: \App\Services\RetentionPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'RetentionRecordType explicitly includes AuditLog and AiLog cases — log retention is a first-class, named scope of the existing retention policy system, not an afterthought.',
            ),
            new GovernanceMappingResult(
                item_key: 'incident_process',
                item_label: 'Incident process',
                owning_class: \App\Models\IncidentEvent::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'IncidentEvent (append-only, correlation_id-linked), StatusPageEvent, and MaintenanceWindow all exist as real, queryable data models. No escalation/notification/on-call process automation exists on top of them — readiness/logging only.',
            ),
            new GovernanceMappingResult(
                item_key: 'deployment_rollback',
                item_label: 'Deployment rollback',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationOrchestrationService::rollback() moves every Applied instance to RolledBack and the run itself to RolledBack. Its own docblock states "no real schema reversal is performed" — Implemented as pure bookkeeping, matching the master plan\'s Phase 16 scope exactly.',
            ),
            new GovernanceMappingResult(
                item_key: 'fleet_migration_enrollment',
                item_label: 'Fleet migration enrollment',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationOrchestrationService::createRun() automatically enrolls every current dedicated/private firm as a Pending instance.',
            ),
            new GovernanceMappingResult(
                item_key: 'per_instance_status',
                item_label: 'Per-instance migration status tracking',
                owning_class: \App\Models\FleetMigrationInstanceStatus::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationInstanceStatus tracks one row per (fleet_migration_run, firm) pair through Pending/Applied/Failed/Skipped/RolledBack — real and thoroughly tested.',
            ),
            new GovernanceMappingResult(
                item_key: 'version_skew_one_minor_version',
                item_label: 'Version skew limited to one minor version',
                owning_class: \App\Services\VersionSkewPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'VersionSkewPolicyService::check() enforces same major version and at most 1 minor version behind SaaS; ahead-of-SaaS also fails.',
            ),
            new GovernanceMappingResult(
                item_key: 'integration_degradation_modes',
                item_label: 'Integration degradation modes',
                owning_class: \App\Services\IntegrationDegradationRegistryService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'IntegrationDegradationRegistryService declares a DegradedBehavior for exactly IntegrationType::{Stripe,EmailProvider,VirusScanning,Telemetry} — 4 of the real external dependencies this repository models. AiProvider (5 cases: OpenAI/Anthropic/Google/AzureOpenAi/AwsBedrock, backed by FirmAiProviderKey) and ConsentChannel::{Sms,WhatsApp} are also real, modeled dependencies with NO declared degradation mode. everyIntegrationHasADeclaredMode() only iterates IntegrationType::cases(), so it would silently report "complete" even though AI/SMS/WhatsApp are uncovered. See the integration_degradation_registry_missing_ai_sms_whatsapp gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'secure_secret_storage',
                item_label: 'Secure secret storage',
                owning_class: \App\Services\EncryptionKeyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TenantEncryptionKey + EncryptionKeyService provide per-firm envelope encryption; FirmAiProviderKey stores its ciphertext via an encrypted cast and hides it from serialization. Real, tested, unmodified by this package.',
            ),
            new GovernanceMappingResult(
                item_key: 'secret_rotation',
                item_label: 'Secret rotation',
                owning_class: \App\Services\AiProviderKeyService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'AiProviderKeyService::rotate() and EncryptionKeyService::rotate() are real, callable rotation capabilities. No automated rotation schedule, age policy, or reminder mechanism exists anywhere (confirmed by direct search) — rotation only happens if a human calls it. See the secret_rotation_schedule_or_reminder_missing gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'firm_owned_ai_api_key_encryption',
                item_label: 'Firm-owned AI/API key encryption',
                owning_class: \App\Models\FirmAiProviderKey::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmAiProviderKey.encrypted_key_ciphertext uses an encrypted cast and is hidden from serialization — envelope-encrypted per firm/provider, matching TenantEncryptionKey/WebhookSecret conventions exactly.',
            ),
            new GovernanceMappingResult(
                item_key: 'no_show_again_after_key_entry',
                item_label: 'Raw key never shown again after entry',
                owning_class: \App\Services\AiProviderKeyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The raw AI provider key is returned exactly once, at AiProviderKeyService::generate()/rotate() call time — never persisted or logged in plaintext, never re-displayable afterward.',
            ),
            new GovernanceMappingResult(
                item_key: 'no_codebase_fork',
                item_label: 'No codebase fork per deployment mode/customer',
                owning_class: null,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Not a property any static scanner can fully machine-prove — represented here as code-level EVIDENCE across a contract/process boundary, not a proof: (1) configuration — DeploymentModeResolutionService/DeploymentConfig resolve behavior from data, not branches in a forked codebase; (2) entitlements — EntitlementService/FirmLicense gate features from data; (3) templates — TemplatePackVersion/InstalledTemplatePack customize per firm from data; (4) webhooks — WebhookSubscription is per-firm configuration, one codebase; (5) APIs — ApiKey/ApiKeyScope are per-firm grants, one codebase; (6) deployment config — DeploymentConfig/DeploymentMode select behavior per firm from one codebase; (7) private enterprise settings — PrivateEnterpriseSettings declares needs from data, not a separate build. This is a real, evidenced architectural pattern, deliberately not overclaimed as a proven guarantee.',
            ),
            new GovernanceMappingResult(
                item_key: 'customization_surfaces_limited',
                item_label: 'Customization surfaces limited to configuration/entitlements/templates/webhooks/APIs',
                owning_class: \App\Services\EntitlementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Every customization point found in this repository is one of exactly those five kinds: EntitlementService/FirmLicense (entitlements), TemplatePackVersion/InstalledTemplatePack (templates), WebhookSubscription (webhooks), ApiKey/ApiKeyScope (APIs), DeploymentConfig/PrivateEnterpriseSettings (configuration). No per-customer code branch or fork mechanism exists anywhere.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function implemented(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function notFound(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function notApplicableYet(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotApplicableYet);
    }

    /**
     * @return array<int, GovernanceMappingResult> every item not classified Implemented
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status !== GovernanceMappingStatus::Implemented,
        ));
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        ));
    }
}
