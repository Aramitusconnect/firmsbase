<?php

namespace App\Services;

/**
 * DefinitionOfDoneReadinessService — backend-only declaration of the
 * master plan's Section 28 Definition of Done checklist. Same pattern
 * as ReleaseChecklistReadinessService: this service does NOT confirm
 * anything automatically. It only declares the required items and
 * reports which of the caller-supplied confirmed keys are missing. A
 * human/process must supply confirmations; this service can never
 * mark a feature done by itself.
 */
class DefinitionOfDoneReadinessService
{
    public const REQUIRED_CHECKS = [
        'entitlement_license_controls' => 'Entitlement and license controls for this feature are in place.',
        'tenant_isolation_enforced_and_tested' => 'Tenant isolation for this feature is enforced and tested.',
        'data_model_state_transitions_documented' => 'Data model state transitions introduced or changed by this feature are documented.',
        'audit_logs_sensitive_actions' => 'Sensitive actions introduced by this feature are audit-logged.',
        'error_states_edge_cases_handled' => 'Error states and edge cases for this feature are handled.',
        'external_dependency_degradation_declared' => 'Degraded behavior for any external dependency this feature relies on has been declared.',
        'client_facing_accessibility_mobile_checks' => 'Client-facing accessibility and mobile-safety checks for this feature are complete.',
        'admin_support_tooling_where_required' => 'Admin/support tooling for this feature exists where required.',
        'tests_pass_release_checklist_complete' => 'All tests for this feature pass and the release checklist is complete.',
    ];

    /**
     * @return array<string, string>
     */
    public function checklist(): array
    {
        return self::REQUIRED_CHECKS;
    }

    /**
     * @param array<string> $confirmed the check keys confirmed for this feature
     */
    public function isComplete(array $confirmed): bool
    {
        return empty($this->missing($confirmed));
    }

    /**
     * @param array<string> $confirmed the check keys confirmed for this feature
     * @return array<int, string> the required check keys not yet confirmed
     */
    public function missing(array $confirmed): array
    {
        return array_values(array_diff(array_keys(self::REQUIRED_CHECKS), $confirmed));
    }
}
