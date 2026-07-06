<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * SecurityBaselineMappingService — declares the 25 cross-cutting
 * security-baseline items from the master plan and maps each to an
 * EXISTING owning mechanism (Phases 1-17) or a known, explicitly
 * declared gap. Purely declarative: this service creates no new
 * enforcement, no new table, and makes no compliance claim by itself
 * — it is a read-only registry that firewall/regression tests assert
 * against so these mappings cannot silently regress.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (migrations, models, services, config) at the
 * time this service was written — not assumed from the master plan
 * alone. See the notes on each item for the specific evidence.
 */
class SecurityBaselineMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'tenant_isolation_query_policy_api_storage',
                item_label: 'Tenant isolation enforced at query/policy/API/storage layers',
                owning_class: \App\Models\Concerns\BelongsToTenant::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'BelongsToTenant adds a global scope that automatically narrows every tenant-owned model query to the active TenantContext firm_id, and stamps firm_id on create. Applied consistently across dozens of models (Document, SecurityEvent, FirmAiProviderKey, DeletionRequest, etc.).',
            ),
            new GovernanceMappingResult(
                item_key: 'database_rls_defense_in_depth',
                item_label: 'PostgreSQL row-level security as defense-in-depth',
                owning_class: null,
                status: GovernanceMappingStatus::PreparedNotEnforced,
                notes: 'Migrations (e.g. 2026_07_04_500001_prepare_row_level_security_for_tenant_tables.php) run ENABLE ROW LEVEL SECURITY and CREATE POLICY on every direct-firm_id table, but deliberately do NOT run FORCE ROW LEVEL SECURITY, and no code anywhere calls SET LOCAL app.current_firm_id. Policies are inert for the app connection today. Follow-up gate: "Phase 1 RLS Enforcement Activation".',
            ),
            new GovernanceMappingResult(
                item_key: 'tenancy_single_resolver',
                item_label: 'A single canonical tenant-context resolver',
                owning_class: \App\Services\TenantContextResolver::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TenantContextResolver is the sole class that sets/reads the active TenantContext; BelongsToTenant and every tenant-safe policy service consume it exclusively.',
            ),
            new GovernanceMappingResult(
                item_key: 'context_consumers_queries_storage_cache_queue_search',
                item_label: 'Tenant context consumed across queries, storage, cache, queue, and search',
                owning_class: \App\Models\Concerns\BelongsToTenant::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Query-level consumption is robust and pervasive via BelongsToTenant. Queue jobs run with no bound TenantContext (no app/Jobs class consumes TenantContextResolver). AI retrieval isolation (AiRetrievalIsolationService) is enforced via an explicit Firm parameter, not the ambient resolver. No cache-key namespacing or dedicated search-layer consumer was found.',
            ),
            new GovernanceMappingResult(
                item_key: 'per_firm_envelope_encryption',
                item_label: 'Per-firm envelope encryption for sensitive data',
                owning_class: \App\Services\EncryptionKeyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TenantEncryptionKey + EncryptionKeyService provision/rotate/destroy one encryption key per firm; documents, AI provider keys, and other sensitive columns encrypt through it.',
            ),
            new GovernanceMappingResult(
                item_key: 'phase17_key_destruction_governance',
                item_label: 'Governed, irreversible cryptographic key destruction',
                owning_class: \App\Services\KeyDestructionApprovalService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Phase 17: KeyDestructionRequestService -> KeyDestructionApprovalService (two-person approval) -> KeyDestructionExecutionService, gated by export/retention/legal-hold clearance. Fully tested.',
            ),
            new GovernanceMappingResult(
                item_key: 'firm_user_2fa',
                item_label: 'Two-factor authentication for firm users',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'users.two_factor_secret/two_factor_recovery_codes/two_factor_confirmed_at columns exist (Laravel default scaffolding, encrypted casts) but no TOTP generation/verification service, no enforcement middleware, and no login flow exist anywhere in the repo.',
            ),
            new GovernanceMappingResult(
                item_key: 'client_portal_2fa',
                item_label: 'Configurable two-factor authentication for client portal logins',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'firm_settings.client_2fa_mode (TwoFactorMode enum: optional/required/disabled) exists but is documented as "Attribute only in Phase 1" — no client portal exists yet and no verification/enforcement logic reads this attribute.',
            ),
            new GovernanceMappingResult(
                item_key: 'csrf_protection',
                item_label: 'CSRF protection on state-changing requests',
                owning_class: null,
                status: GovernanceMappingStatus::FrameworkDefaultOnly,
                notes: 'bootstrap/app.php registers no custom middleware; Laravel\'s unmodified default "web" middleware group (which includes CSRF verification) applies to routes/web.php. No real form/state-changing endpoint exists yet to exercise it.',
            ),
            new GovernanceMappingResult(
                item_key: 'secure_cookies',
                item_label: 'Secure, HttpOnly, SameSite cookies',
                owning_class: null,
                status: GovernanceMappingStatus::FrameworkDefaultOnly,
                notes: 'config/session.php: http_only defaults to true, same_site defaults to "lax", secure is env-driven (not forced true). All framework defaults, no app-specific hardening layered on top.',
            ),
            new GovernanceMappingResult(
                item_key: 'session_timeout',
                item_label: 'Session idle/absolute timeout',
                owning_class: null,
                status: GovernanceMappingStatus::FrameworkDefaultOnly,
                notes: 'config/session.php lifetime defaults to 120 minutes via env(). No custom idle-timeout or absolute-timeout logic exists.',
            ),
            new GovernanceMappingResult(
                item_key: 'login_rate_limits',
                item_label: 'Login attempt rate limiting',
                owning_class: null,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'No login route/controller/authentication flow exists anywhere in the repo (routes/web.php only serves the default welcome view) — there is nothing to attach a rate limiter to yet.',
            ),
            new GovernanceMappingResult(
                item_key: 'password_rules',
                item_label: 'Password strength/rotation rules',
                owning_class: null,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'No registration or password-set/reset flow exists; Illuminate\'s Password validation rule is never referenced anywhere in the codebase.',
            ),
            new GovernanceMappingResult(
                item_key: 'suspicious_login_events',
                item_label: 'Suspicious login detection/event recording',
                owning_class: \App\Models\SecurityEvent::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'SecurityEvent is a generic, append-only, firm-scoped event log with free-text event_type/category fields capable of representing a suspicious-login event, per approved decision #7 (reuse the generic model, no dedicated SuspiciousLoginService/columns). No automatic detection logic exists yet — this is structural capacity, not active detection.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_file_storage',
                item_label: 'Documents stored privately by default',
                owning_class: \App\Services\DocumentSecurityService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DocumentSecurityService::canAccess() is the explicit firm-scoped access gate; documents default to the "local" disk, never "public".',
            ),
            new GovernanceMappingResult(
                item_key: 'no_public_legal_document_urls',
                item_label: 'No legal document is ever served from a public URL',
                owning_class: \App\Models\Document::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Document\'s own docblock states "private by default, never a public URL" as a project rule; confirmed no document-related code path ever writes to or reads from the "public" disk.',
            ),
            new GovernanceMappingResult(
                item_key: 'signed_temporary_urls_only_when_needed',
                item_label: 'Signed, time-limited URLs used only when a document must be shared',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No SignedUrlService, Storage::temporaryUrl() call, or signedRoute() usage exists anywhere in the repo.',
            ),
            new GovernanceMappingResult(
                item_key: 'signed_urls_tenant_context_authorized_users',
                item_label: 'Signed URLs scoped to tenant context and authorized users',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'Depends on signed_temporary_urls_only_when_needed, which does not exist yet. DocumentSecurityService::canAccess() is the documented gate any future signed-URL endpoint must call first.',
            ),
            new GovernanceMappingResult(
                item_key: 'malware_scanning_before_document_acceptance',
                item_label: 'Malware/virus scanning gates document acceptance',
                owning_class: \App\Services\VirusScan\FakeVirusScanner::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'The gate itself is real and enforced: DocumentSecurityService only allows Approved once scan_status is Clean, applied via ScanDocumentJob. But VirusScanner\'s only implementation is FakeVirusScanner (deterministic, no real engine) — see gap "real_malware_scanning_engine_stubbed".',
            ),
            new GovernanceMappingResult(
                item_key: 'ai_retrieval_isolation_per_firm',
                item_label: 'AI retrieval isolated per firm (dedicated namespace)',
                owning_class: \App\Services\AiRetrievalIsolationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'provisionFor() records one unique namespace_identifier per firm; buildContext() throws on any cross-firm matter reference.',
            ),
            new GovernanceMappingResult(
                item_key: 'ai_matter_permission_enforcement',
                item_label: 'AI retrieval enforces matter-level user permission',
                owning_class: \App\Services\MatterAccessPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'canAccessMatter()/canAccessAllMatters() enforce role-based blanket access (FirmOwner/Attorney) or active MatterAssignment for other roles; consumed by AiRetrievalIsolationService::buildContext().',
            ),
            new GovernanceMappingResult(
                item_key: 'prompt_injection_resistance',
                item_label: 'Prompt-injection resistance for document-derived AI input',
                owning_class: \App\Services\PromptInjectionResistanceService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Deterministic denylist detection (detectsInjectionAttempt()) plus explicit untrusted-data wrapping (wrapAsUntrustedData()), as defense-in-depth on top of FakeAiProviderAdapter\'s structural guarantee.',
            ),
            new GovernanceMappingResult(
                item_key: 'audit_logging_required_categories',
                item_label: 'Audit logging required across all mandated categories',
                owning_class: \App\Services\AuditPreservationPolicyService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'protectedLogFamilies() declares all 10 GovernanceRecordScope families; 8 of 10 have a confirmed, append-only-guarded backing model (SecurityEvent, PaymentClassificationEvent, TrustLedgerEntry, PdfViewEvent, SupportAccessSession, PlatformBillingEvent, AiUsageEvent, WebhookEvent). ClientPortalLog and ApiLog are explicit, declared gaps (no table invented for either).',
            ),
            new GovernanceMappingResult(
                item_key: 'reason_required_time_limited_support_access',
                item_label: 'Reason-required, time-limited platform support access',
                owning_class: \App\Models\SupportAccessRequest::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'SupportAccessRequest requires a reason and firm approval (unless emergency); SupportAccessSession is time-limited via expires_at, enforced by isCurrentlyValid(), never by status alone.',
            ),
            new GovernanceMappingResult(
                item_key: 'two_person_approval_high_risk_key_destruction',
                item_label: 'Two-person approval required for key destruction',
                owning_class: \App\Services\KeyDestructionApprovalService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'firstApprove()/secondApprove() require two distinct platform admins before KeyDestructionExecutionService may execute; backed by HighRiskChangeType::CryptographicKeyDestruction reusing the existing two-person-approval workflow (no second approval system).',
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
     * @return array<int, GovernanceMappingResult> every item classified Implemented
     */
    public function implemented(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === GovernanceMappingStatus::Implemented,
        ));
    }
}
