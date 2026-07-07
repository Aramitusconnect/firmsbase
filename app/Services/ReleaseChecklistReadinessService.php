<?php

namespace App\Services;

/**
 * ReleaseChecklistReadinessService — backend-only declaration of the
 * master plan's Section 28 release checklist. Direct structural
 * mirror of BillingAccessibilityReadinessService's pattern
 * (documentation-as-code checklist + confirmed-keys diff), extended
 * with a release-governance purpose. This service does NOT confirm
 * anything automatically — it never inspects the database, the
 * filesystem, or any external system to decide whether a checklist
 * item is actually satisfied. It only declares the required items and
 * reports which of the caller-supplied confirmed keys are missing.
 * A human/process must supply confirmations; this service can never
 * mark a release ready by itself.
 */
class ReleaseChecklistReadinessService
{
    public const REQUIRED_CHECKS = [
        'migrations_reviewed_expand_contract_reversible' => 'Every migration in this release has been reviewed for expand/contract discipline and is reversible.',
        'policies_rls_middleware_reviewed' => 'Access policies, row-level security posture, and any tenant middleware have been reviewed.',
        'seed_data_reviewed_no_test_secrets' => 'Seed data has been reviewed and contains no test secrets or unsafe defaults for this environment.',
        'critical_workflows_automated_tests' => 'Critical workflows in this release are covered by automated tests.',
        'no_public_document_urls' => 'No legal document is exposed via a public URL.',
        'no_cross_firm_data_exposure' => 'No change in this release exposes one firm\'s data to another firm.',
        'queues_scheduler_running_where_required' => 'Queues and the scheduler are confirmed running wherever this release requires them.',
        'monitoring_alerting_configured_for_production' => 'Monitoring and alerting are configured for the production environment.',
        'rollback_plan_documented' => 'A rollback plan for this release has been documented.',
        'security_sensitive_changes_reviewed' => 'Every security-sensitive change in this release has been reviewed.',
    ];

    /**
     * @return array<string, string>
     */
    public function checklist(): array
    {
        return self::REQUIRED_CHECKS;
    }

    /**
     * @param array<string> $confirmed the check keys a release manager has confirmed
     */
    public function isComplete(array $confirmed): bool
    {
        return empty($this->missing($confirmed));
    }

    /**
     * @param array<string> $confirmed the check keys a release manager has confirmed
     * @return array<int, string> the required check keys not yet confirmed
     */
    public function missing(array $confirmed): array
    {
        return array_values(array_diff(array_keys(self::REQUIRED_CHECKS), $confirmed));
    }
}
