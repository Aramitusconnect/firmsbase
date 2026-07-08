<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * PrePilotRemediationBacklogService — Section 38's read-only pre-pilot
 * backlog. It does not fix anything: it reads the LIVE
 * ComplianceGapRegistryService::all() output (never a hardcoded copy)
 * and classifies each currently-registered gap by pilot gate,
 * remediation order, UI-build safety, production-hardening impact, and
 * launch readiness, then turns those findings into an ordered
 * pre-pilot action plan plus the surrounding UI/hardening/legal/
 * support/demo-data checklists a first real-law-firm pilot needs.
 *
 * "Real pilot data" throughout this service means the first real law
 * firm / legal client / real matter data — never synthetic demo data,
 * fake seed data, or internal test data.
 *
 * Re-runnable: all() re-reads ComplianceGapRegistryService::all() on
 * every call. Any gap key present in the live registry but absent from
 * GATE_CLASSIFICATION is NOT silently dropped — it is still returned,
 * conservatively classified into the real_pilot_data gate, with a
 * notes marker flagging it as unclassified so a future section must
 * triage it explicitly (see unclassifiedGapKeys()).
 */
class PrePilotRemediationBacklogService
{
    public const GATE_REAL_PILOT_DATA = 'real_pilot_data';

    public const GATE_CLIENT_PORTAL_MOBILE = 'client_portal_mobile';

    public const GATE_PAYMENT_PILOT = 'payment_pilot';

    public const GATE_TRUST_PILOT = 'trust_pilot';

    public const GATE_AI_PILOT = 'ai_pilot';

    public const GATE_DEDICATED_PRIVATE_ENTERPRISE = 'dedicated_private_enterprise';

    public const GATE_PRODUCTION_HARDENING = 'production_hardening';

    public const GATE_POST_PILOT = 'post_pilot';

    private const UNCLASSIFIED_MARKER = '[UNCLASSIFIED_GAP]';

    /**
     * Classification map for every gap key known in
     * ComplianceGapRegistryService at the time this service was
     * written (21 keys). Each entry: primary_gate (the one primary
     * remediation bucket), additional_gates (other pilot gates this
     * gap also blocks), order (1-21 remediation priority, lower runs
     * first), fix_before (why/what it blocks), fix_after (a
     * prerequisite gap key, or null if none), owning_class (the most
     * relevant existing mapping/domain-readiness class, or null).
     */
    private const GATE_CLASSIFICATION = [
        'rls_prepared_not_enforced' => [
            'primary_gate' => self::GATE_REAL_PILOT_DATA,
            'additional_gates' => [self::GATE_DEDICATED_PRIVATE_ENTERPRISE],
            'order' => 1,
            'fix_before' => 'any real firm/client/matter data reaching a shared-schema SaaS instance',
            'fix_after' => null,
            'owning_class' => RowLevelSecurityCoverageMappingService::class,
        ],
        'firm_user_2fa_missing' => [
            'primary_gate' => self::GATE_REAL_PILOT_DATA,
            'additional_gates' => [self::GATE_CLIENT_PORTAL_MOBILE],
            'order' => 2,
            'fix_before' => 'any real firm-user login against real client/matter data',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'emergency_support_access_high_risk_approval_not_wired' => [
            'primary_gate' => self::GATE_REAL_PILOT_DATA,
            'additional_gates' => [self::GATE_PRODUCTION_HARDENING],
            'order' => 3,
            'fix_before' => 'any platform-support access to a real firm\'s real matter/client data',
            'fix_after' => null,
            'owning_class' => EmergencyAccessGovernanceGapService::class,
        ],
        'trust_ledger_entry_posting_actor_not_guaranteed' => [
            'primary_gate' => self::GATE_TRUST_PILOT,
            'additional_gates' => [],
            'order' => 4,
            'fix_before' => 'trust pilot exit / before real trust funds are enabled',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'login_policy_wrappers_missing' => [
            'primary_gate' => self::GATE_REAL_PILOT_DATA,
            'additional_gates' => [self::GATE_CLIENT_PORTAL_MOBILE],
            'order' => 5,
            'fix_before' => 'any real login/registration HTTP flow going live',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'seed_data_defaults_and_test_secrets_not_audited' => [
            'primary_gate' => self::GATE_PRODUCTION_HARDENING,
            'additional_gates' => [self::GATE_REAL_PILOT_DATA],
            'order' => 6,
            'fix_before' => 'any real pilot deployment (the literal test@example.com/"password" default must not exist on an instance holding real data)',
            'fix_after' => null,
            'owning_class' => ReleaseChecklistReadinessService::class,
        ],
        'restore_tests_do_not_exercise_real_restore_path' => [
            'primary_gate' => self::GATE_PRODUCTION_HARDENING,
            'additional_gates' => [self::GATE_DEDICATED_PRIVATE_ENTERPRISE],
            'order' => 7,
            'fix_before' => 'production hardening sign-off and before any dedicated/private restore rehearsal is trusted',
            'fix_after' => null,
            'owning_class' => OperationalReadinessMappingService::class,
        ],
        'client_portal_2fa_missing' => [
            'primary_gate' => self::GATE_CLIENT_PORTAL_MOBILE,
            'additional_gates' => [],
            'order' => 8,
            'fix_before' => 'any client portal/mobile login surface being built',
            'fix_after' => 'firm_user_2fa_missing',
            'owning_class' => MobilePortalCoverageMappingService::class,
        ],
        'signed_document_url_service_missing' => [
            'primary_gate' => self::GATE_CLIENT_PORTAL_MOBILE,
            'additional_gates' => [],
            'order' => 9,
            'fix_before' => 'any client/external document sharing or download flow',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'stripe_disconnect_payment_collection_block_not_enforced' => [
            'primary_gate' => self::GATE_PAYMENT_PILOT,
            'additional_gates' => [],
            'order' => 10,
            'fix_before' => 'any firm-client online payment collection pilot',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'client_facing_payment_receipts_missing' => [
            'primary_gate' => self::GATE_PAYMENT_PILOT,
            'additional_gates' => [self::GATE_CLIENT_PORTAL_MOBILE],
            'order' => 11,
            'fix_before' => 'any client-facing payment confirmation/receipt surface',
            'fix_after' => 'stripe_disconnect_payment_collection_block_not_enforced',
            'owning_class' => MobilePortalCoverageMappingService::class,
        ],
        'ai_jobs_not_cancelled_when_entitlement_removed' => [
            'primary_gate' => self::GATE_AI_PILOT,
            'additional_gates' => [],
            'order' => 12,
            'fix_before' => 'any AI pilot allowing a Pending approval to outlive the firm\'s AI entitlement',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'integration_degradation_registry_missing_ai_sms_whatsapp' => [
            'primary_gate' => self::GATE_AI_PILOT,
            'additional_gates' => [self::GATE_CLIENT_PORTAL_MOBILE],
            'order' => 13,
            'fix_before' => 'any AI pilot or SMS/WhatsApp-dependent client-portal rollout that needs a declared degradation mode',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'org_admin_role_missing' => [
            'primary_gate' => self::GATE_DEDICATED_PRIVATE_ENTERPRISE,
            'additional_gates' => [],
            'order' => 14,
            'fix_before' => 'any organization-level (as opposed to firm-level) administration UI, and before dedicated/private organization deals',
            'fix_after' => null,
            'owning_class' => PermissionMatrixMappingService::class,
        ],
        'template_pack_per_pack_commercial_differentiation_missing' => [
            'primary_gate' => self::GATE_POST_PILOT,
            'additional_gates' => [],
            'order' => 15,
            'fix_before' => 'a future per-pack commercialization/pricing initiative — does not block a first narrow pilot',
            'fix_after' => null,
            'owning_class' => TemplatePackCoverageMappingService::class,
        ],
        'real_malware_scanning_engine_stubbed' => [
            'primary_gate' => self::GATE_PRODUCTION_HARDENING,
            'additional_gates' => [],
            'order' => 16,
            'fix_before' => 'production hardening sign-off (the acceptance gate itself is real; only the scanning engine behind it is a stub)',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'secret_rotation_schedule_or_reminder_missing' => [
            'primary_gate' => self::GATE_PRODUCTION_HARDENING,
            'additional_gates' => [self::GATE_DEDICATED_PRIVATE_ENTERPRISE],
            'order' => 17,
            'fix_before' => 'production hardening sign-off',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'auth_admin_override_events_generic_only' => [
            'primary_gate' => self::GATE_POST_PILOT,
            'additional_gates' => [],
            'order' => 18,
            'fix_before' => 'a future dedicated authentication/admin-override audit model — the generic SecurityEvent/TimelineEventRecorder mapping is an accepted decision, not a pilot blocker',
            'fix_after' => null,
            'owning_class' => null,
        ],
        'ai_approval_request_lifecycle_states_incomplete' => [
            'primary_gate' => self::GATE_POST_PILOT,
            'additional_gates' => [],
            'order' => 19,
            'fix_before' => 'a future AI workflow-richness phase — the operative human-approval gate is already real and enforced',
            'fix_after' => null,
            'owning_class' => WorkflowStateCatalogMappingService::class,
        ],
        'form_edition_watch_sla_controls_missing' => [
            'primary_gate' => self::GATE_POST_PILOT,
            'additional_gates' => [],
            'order' => 20,
            'fix_before' => 'a future template-governance/admin UI phase',
            'fix_after' => null,
            'owning_class' => AdminControlCatalogMappingService::class,
        ],
        'template_language_fallback_staff_notification_missing' => [
            'primary_gate' => self::GATE_POST_PILOT,
            'additional_gates' => [],
            'order' => 21,
            'fix_before' => 'a future multi-language template phase',
            'fix_after' => null,
            'owning_class' => null,
        ],
    ];

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        $registry = new ComplianceGapRegistryService();
        $results = [];

        foreach ($registry->all() as $gapItem) {
            $classification = self::GATE_CLASSIFICATION[$gapItem->key] ?? null;

            if ($classification === null) {
                $results[$gapItem->key] = new GovernanceMappingResult(
                    item_key: $gapItem->key,
                    item_label: $gapItem->description,
                    owning_class: null,
                    status: GovernanceMappingStatus::PartiallyImplemented,
                    notes: sprintf(
                        '%s Gap "%s" (severity %s, area %s) exists in the live ComplianceGapRegistryService but has no entry in this backlog\'s classification map. Conservatively classified into the %s pilot gate until explicitly triaged — it must not be silently dropped from the pre-pilot plan.',
                        self::UNCLASSIFIED_MARKER,
                        $gapItem->key,
                        $gapItem->severity->value,
                        $gapItem->area,
                        self::GATE_REAL_PILOT_DATA,
                    ),
                );

                continue;
            }

            $gateList = implode(', ', array_merge([$classification['primary_gate']], $classification['additional_gates']));

            $results[$gapItem->key] = new GovernanceMappingResult(
                item_key: $gapItem->key,
                item_label: $gapItem->description,
                owning_class: $classification['owning_class'],
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: sprintf(
                    'Severity: %s. Area: %s. Primary pilot gate: %s. Pilot gates affected: %s. Remediation order: %d of 21. Fix before: %s. Fix after: %s. Original registry description: %s',
                    $gapItem->severity->value,
                    $gapItem->area,
                    $classification['primary_gate'],
                    $gateList,
                    $classification['order'],
                    $classification['fix_before'],
                    $classification['fix_after'] ?? 'no prerequisite gap — may be remediated independently',
                    $gapItem->description,
                ),
            );
        }

        return $results;
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function gapKeys(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<int, string>
     */
    public function unclassifiedGapKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, self::UNCLASSIFIED_MARKER),
        ));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function byPilotGate(string $gate): array
    {
        $all = $this->all();

        return array_filter(
            $all,
            function (GovernanceMappingResult $item, string $key) use ($gate) {
                $classification = self::GATE_CLASSIFICATION[$key] ?? null;

                if ($classification === null) {
                    // Unclassified gaps are conservatively treated as
                    // real_pilot_data blockers so they are never
                    // silently missed from the most restrictive gate.
                    return $gate === self::GATE_REAL_PILOT_DATA;
                }

                return $classification['primary_gate'] === $gate
                    || in_array($gate, $classification['additional_gates'], true);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function realPilotDataBlockers(): array
    {
        return $this->byPilotGate(self::GATE_REAL_PILOT_DATA);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function clientPortalMobileBlockers(): array
    {
        return $this->byPilotGate(self::GATE_CLIENT_PORTAL_MOBILE);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function paymentPilotBlockers(): array
    {
        return $this->byPilotGate(self::GATE_PAYMENT_PILOT);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function trustPilotBlockers(): array
    {
        return $this->byPilotGate(self::GATE_TRUST_PILOT);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function aiPilotBlockers(): array
    {
        return $this->byPilotGate(self::GATE_AI_PILOT);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function dedicatedPrivateEnterpriseBlockers(): array
    {
        return $this->byPilotGate(self::GATE_DEDICATED_PRIVATE_ENTERPRISE);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function productionHardeningBlockers(): array
    {
        return $this->byPilotGate(self::GATE_PRODUCTION_HARDENING);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function postPilotBacklog(): array
    {
        return $this->byPilotGate(self::GATE_POST_PILOT);
    }

    /**
     * @return array<int, array{order: int, key: string, item: GovernanceMappingResult}>
     */
    public function remediationOrder(): array
    {
        $all = $this->all();
        $ordered = [];

        foreach ($all as $key => $item) {
            $order = self::GATE_CLASSIFICATION[$key]['order'] ?? 999;
            $ordered[] = ['order' => $order, 'key' => $key, 'item' => $item];
        }

        usort($ordered, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $ordered;
    }

    /**
     * @return array<int, array{module: string, safe: bool, notes: string}>
     */
    public function safeFirstPilotUiScope(): array
    {
        return [
            ['module' => 'platform_admin_shell_basic_dashboard', 'safe' => true, 'notes' => 'Safe only if route/policy/entitlement checks are enforced server-side for every action — never hidden-navigation-only.'],
            ['module' => 'firm_onboarding_internal_settings', 'safe' => true, 'notes' => 'Firm-internal only; no organization-level admin scope.'],
            ['module' => 'users_roles_current_permission_boundaries', 'safe' => true, 'notes' => 'Excludes any org_admin screen until org_admin_role_missing is fixed.'],
            ['module' => 'clients', 'safe' => true, 'notes' => 'Internal firm-facing CRUD only.'],
            ['module' => 'matters', 'safe' => true, 'notes' => 'Internal firm-facing CRUD only.'],
            ['module' => 'conflicts', 'safe' => true, 'notes' => 'Internal conflict-check review UI only.'],
            ['module' => 'tasks_deadlines', 'safe' => true, 'notes' => 'Internal firm-facing only.'],
            ['module' => 'documents_internal_only', 'safe' => true, 'notes' => 'Excludes client portal/signed-url external sharing until signed_document_url_service_missing is fixed.'],
            ['module' => 'template_pack_install_preview_pilot_pack_only', 'safe' => true, 'notes' => 'Limited to the single pilot pack, install/preview only.'],
            ['module' => 'manual_payment_recording_only', 'safe' => true, 'notes' => 'Excludes online Stripe collection/client receipts until payment-pilot gaps are fixed.'],
            ['module' => 'billing_invoices_internal_only', 'safe' => true, 'notes' => 'Internal invoice drafting/lifecycle only, no client-facing payment surface.'],
            ['module' => 'trust_iolta_ui', 'safe' => false, 'notes' => 'Blocked until trust pilot readiness (trust_ledger_entry_posting_actor_not_guaranteed) is resolved.'],
            ['module' => 'ai_ui', 'safe' => false, 'notes' => 'Blocked until AI job-lifecycle/degradation gaps are resolved.'],
            ['module' => 'client_portal_mobile', 'safe' => false, 'notes' => 'Blocked until client-portal/mobile blockers are resolved.'],
        ];
    }

    /**
     * @return array<int, array{module: string, blocked_by: array<int, string>, notes: string}>
     */
    public function uiModulesThatMustWait(): array
    {
        return [
            ['module' => 'client_portal_mobile', 'blocked_by' => ['client_portal_2fa_missing', 'firm_user_2fa_missing', 'login_policy_wrappers_missing'], 'notes' => 'No login-hardened client-facing surface may launch before these authentication gaps are fixed.'],
            ['module' => 'online_payment_stripe_checkout', 'blocked_by' => ['stripe_disconnect_payment_collection_block_not_enforced'], 'notes' => 'Online collection must not launch before disconnect handling is enforced.'],
            ['module' => 'client_facing_receipts', 'blocked_by' => ['client_facing_payment_receipts_missing'], 'notes' => 'No client-facing payment confirmation surface exists to build against yet.'],
            ['module' => 'trust_iolta_ui', 'blocked_by' => ['trust_ledger_entry_posting_actor_not_guaranteed'], 'notes' => 'Trust pilot exit criteria are not yet met.'],
            ['module' => 'ai_ui', 'blocked_by' => ['ai_jobs_not_cancelled_when_entitlement_removed', 'integration_degradation_registry_missing_ai_sms_whatsapp'], 'notes' => 'AI job-lifecycle and degradation coverage must be closed first.'],
            ['module' => 'org_admin_ui', 'blocked_by' => ['org_admin_role_missing'], 'notes' => 'No organization-level admin role/model exists to build against yet.'],
            ['module' => 'dedicated_private_enterprise_fleet_ui', 'blocked_by' => ['org_admin_role_missing', 'restore_tests_do_not_exercise_real_restore_path', 'rls_prepared_not_enforced', 'secret_rotation_schedule_or_reminder_missing'], 'notes' => 'Dedicated/private deals require fleet/offline-license rehearsal and RLS/backup hardening first.'],
            ['module' => 'public_client_document_download_flows', 'blocked_by' => ['signed_document_url_service_missing'], 'notes' => 'No signed, time-limited URL mechanism exists to gate external document access.'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function universalUiContract(): array
    {
        return [
            'Resolve tenant context through the existing tenant context pattern (TenantContextResolver) before any query.',
            'Enforce backend route/page authorization for every action — never hidden navigation alone.',
            'Check the relevant policy/access service (e.g. PlatformStaffAccessPolicyService, MatterAccessPolicyService) before rendering sensitive data.',
            'Check EntitlementService for module access before any mutation.',
            'Use feature flags only to restrict access an entitlement already grants — never to widen it.',
            'Audit every sensitive create/update/delete/approval/access action.',
            'Run PaymentClassificationService before any payment persistence or provider intent.',
            'Use the canonical Payment ledger for every payment/collection record — no parallel ledger.',
            'Check ConsentService for a granted, unrevoked consent record before any SMS/WhatsApp/email automation.',
            'Use the signed-URL service once client/external document access exists — never an unsigned/permanent link.',
            'Never introduce a second audit, permission, entitlement, license, or signature system.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function productionHardeningChecklist(): array
    {
        return [
            'RLS enforcement activation (FORCE ROW LEVEL SECURITY + SET LOCAL wiring) and a broken-scope test proving it catches a bypassed application-layer scope.',
            'Real backup runner (replacing FakeBackupRestoreDrillRunner-only coverage).',
            'Real restore rehearsal exercised against the real backup path.',
            'Queue worker supervision.',
            'Scheduler supervision.',
            'Failed-job alerting.',
            'Real malware scanning engine (replacing FakeVirusScanner).',
            'Secret rotation schedule/reminder for AI provider keys and encryption keys.',
            'Production .env audit.',
            'Test/demo secret audit (no literal test@example.com/"password" defaults on a real instance).',
            'Logging/monitoring/error alerting.',
            'HTTPS/domain/security headers.',
            'Database backup encryption.',
            'Storage privacy review.',
            'Rollback plan.',
            'Incident response checklist.',
            'Fleet/offline-license rehearsal for dedicated/private deployments.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function legalCommercialLaunchChecklist(): array
    {
        return [
            'Terms of Service.',
            'Privacy Policy.',
            'Data Processing Addendum.',
            'Subprocessor list.',
            'Acceptable Use Policy.',
            'AI usage disclaimer.',
            'No-legal-advice disclaimer.',
            'No-automatic-filing disclaimer.',
            'Trust/IOLTA limitation and jurisdiction disclaimer.',
            'Communication consent language.',
            'Retention/offboarding policy.',
            'Support/SLA policy.',
            'Pilot agreement.',
            'Security/privacy summary for firms.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function supportEmergencyWorkflowChecklist(): array
    {
        return [
            'Emergency access requires platform approval (wire emergency_support_access_high_risk_approval_not_wired before pilot).',
            'Non-empty reason required for every emergency/support access request.',
            'Duration/timebox enforced on every granted access.',
            'Firm notification on emergency/support access.',
            'Full audit trail of the request/approval/access lifecycle.',
            'Support access request approval path (not just emergency access).',
            'Escalation/incident severity levels defined.',
            'Customer support intake process.',
            'Bug triage process.',
            'Data-access review process.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function demoSandboxDataChecklist(): array
    {
        return [
            'Demo firm — synthetic only, clearly labeled.',
            'Demo firm owner/user roles — synthetic only.',
            'Demo clients — synthetic only, no real client data.',
            'Demo matters — synthetic only, no real matter data.',
            'Demo conflict data — synthetic only.',
            'Demo document request items — synthetic only.',
            'Demo fake documents — synthetic only, no real legal content.',
            'Demo invoices/manual payments — synthetic only, no real financial data.',
            'Demo payment plan scenarios — synthetic only.',
            'Demo template pack — synthetic only.',
            'Demo deadlines/tasks — synthetic only.',
            'Every demo/sandbox record clearly labeled "synthetic only".',
            'No real client/legal data in any demo/sandbox dataset.',
            'No real secrets/API keys in any demo/sandbox dataset.',
            'Seeder/factory audit before production (see seed_data_defaults_and_test_secrets_not_audited).',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function acceptanceTestsBeforeGate(string $gate): array
    {
        $acceptanceMatrix = new AcceptanceTestMatrixMappingService();

        $groupsByGate = match ($gate) {
            self::GATE_REAL_PILOT_DATA => ['tenant_isolation', 'security'],
            self::GATE_CLIENT_PORTAL_MOBILE => ['accessibility_mobile', 'notifications_consent'],
            self::GATE_PAYMENT_PILOT => ['billing'],
            self::GATE_TRUST_PILOT => ['trust'],
            self::GATE_AI_PILOT => ['ai'],
            self::GATE_DEDICATED_PRIVATE_ENTERPRISE => ['reliability_fleet'],
            self::GATE_PRODUCTION_HARDENING => ['reliability_fleet'],
            self::GATE_POST_PILOT => [],
            default => [],
        };

        $keys = [];

        foreach ($groupsByGate as $group) {
            $keys = array_merge($keys, array_keys($acceptanceMatrix->group($group)));
        }

        return $keys;
    }

    /**
     * @return array<int, array{step: int, title: string}>
     */
    public function finalRecommendedOrder(): array
    {
        return [
            ['step' => 1, 'title' => 'Fix real-pilot-data blockers.'],
            ['step' => 2, 'title' => 'Build minimal admin/firm-internal UI only.'],
            ['step' => 3, 'title' => 'Fix client-portal/mobile blockers.'],
            ['step' => 4, 'title' => 'Build minimal client portal/mobile if still desired.'],
            ['step' => 5, 'title' => 'Fix payment-pilot blockers.'],
            ['step' => 6, 'title' => 'Build payment UI/payment collection pilot.'],
            ['step' => 7, 'title' => 'Complete production hardening blockers.'],
            ['step' => 8, 'title' => 'Fix trust-pilot blockers.'],
            ['step' => 9, 'title' => 'Build trust UI/pilot if desired.'],
            ['step' => 10, 'title' => 'Fix AI-pilot blockers.'],
            ['step' => 11, 'title' => 'Build AI UI/pilot if desired.'],
            ['step' => 12, 'title' => 'Prepare pilot launch package: legal docs, support workflow, demo data, training, onboarding.'],
            ['step' => 13, 'title' => 'Invite first pilot firms.'],
        ];
    }

    /**
     * No new gap is warranted by this section: every currently-open
     * gap was classifiable against the live registry. Empty by design.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        return [];
    }
}
