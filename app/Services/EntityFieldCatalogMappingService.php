<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * EntityFieldCatalogMappingService — declares the master plan's
 * Section 32 detailed entity field catalog (31 tables) and maps every
 * representative field to the real, existing schema/model/service
 * evidence found by direct repository inspection, or honestly
 * classifies it NotFound. Purely declarative — no migration, no new
 * table, no new column, no new enum, no new value object. Field names
 * in the master plan's catalog are representative only (finalized
 * during schema design), so a cosmetic rename, a replacing foreign
 * key, a child table, a JSON column, or a service/derived value all
 * count as real representation — never re-derived as a literal
 * missing column. Reuses GovernanceMappingResult/GovernanceMappingStatus
 * from the Section 25 cross-cutting package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all relevant database/migrations and
 * app/Models) at the time this service was written.
 */
class EntityFieldCatalogMappingService
{
    private const TABLES = [
        'organizations', 'billing_accounts', 'firms', 'firm_settings', 'firm_licenses',
        'seat_pools', 'module_catalog', 'firm_entitlements', 'communication_consents',
        'firm_leads', 'consultations', 'clients', 'contacts', 'matters', 'parties',
        'matter_parties', 'conflict_check_runs', 'documents', 'tasks', 'task_dependencies',
        'deadlines', 'payment_plans', 'payment_plan_installments', 'payments',
        'platform_invoices', 'license_files', 'trust_ledger_entries', 'document_templates',
        'form_edition_watch_items', 'ai_usage_events', 'tenant_encryption_keys', 'activity_logs',
    ];

    /**
     * Tables that carry no firm_id of their own and are scoped
     * transitively through a parent row — matches
     * DataModelContractMappingService::firm_id_on_tenant_tables notes
     * exactly (matter_parties, task_dependencies) plus the additional
     * transitively-scoped tables this catalog covers.
     */
    private const TRANSITIVELY_SCOPED_TABLES = [
        'matter_parties' => 'matter_id -> matters.firm_id',
        'task_dependencies' => 'task_id -> tasks.firm_id',
        'payment_plan_installments' => 'payment_plan_id -> payment_plans.firm_id',
    ];

    /**
     * Tables that deliberately carry no firm_id because they sit above
     * or beside the firm tenancy boundary (global/organization-owned),
     * matching DataModelContractMappingService::global_commercial_tables.
     */
    private const GLOBAL_OR_ORGANIZATION_OWNED_TABLES = [
        'organizations' => 'the tenancy root itself',
        'billing_accounts' => 'organization-owned commercial record',
        'seat_pools' => 'organization-owned pooled seats',
        'module_catalog' => 'global reference catalog',
    ];

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge(
            $this->organizations(),
            $this->billingAccounts(),
            $this->firms(),
            $this->firmSettings(),
            $this->firmLicenses(),
            $this->seatPools(),
            $this->moduleCatalog(),
            $this->firmEntitlements(),
            $this->communicationConsents(),
            $this->firmLeads(),
            $this->consultations(),
            $this->clients(),
            $this->contacts(),
            $this->matters(),
            $this->parties(),
            $this->matterParties(),
            $this->conflictCheckRuns(),
            $this->documents(),
            $this->tasks(),
            $this->taskDependencies(),
            $this->deadlines(),
            $this->paymentPlans(),
            $this->paymentPlanInstallments(),
            $this->payments(),
            $this->platformInvoices(),
            $this->licenseFiles(),
            $this->trustLedgerEntries(),
            $this->documentTemplates(),
            $this->formEditionWatchItems(),
            $this->aiUsageEvents(),
            $this->tenantEncryptionKeys(),
            $this->activityLogs(),
        );
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function table(string $table): array
    {
        return array_filter(
            $this->all(),
            fn (string $key) => str_starts_with($key, "{$table}."),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<int, string>
     */
    public function tables(): array
    {
        return self::TABLES;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function implemented(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function notFound(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * Table-level rollup: Implemented if every field in the table is
     * Implemented, PartiallyImplemented if at least one field is
     * PartiallyImplemented or NotFound but most are represented,
     * NotFound only if the table itself has no schema representation
     * at all (only activity_logs).
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function tableCoverage(): array
    {
        $coverage = [];

        foreach (self::TABLES as $table) {
            $fields = $this->table($table);
            $statuses = array_map(fn (GovernanceMappingResult $f) => $f->status, $fields);

            if ($table === 'activity_logs') {
                $coverage[$table] = new GovernanceMappingResult(
                    item_key: $table,
                    item_label: "{$table} table-level coverage",
                    owning_class: \App\Services\TimelineEventRecorder::class,
                    status: GovernanceMappingStatus::Implemented,
                    notes: 'No activity_logs table exists and none is created here (matches DataModelContractMappingService::activityLogsInterpretation()). Represented by two existing, unmodified audit primitives: SecurityEvent and TimelineEventRecorder/timeline_events. A documented equivalence, not a gap.',
                );

                continue;
            }

            $allImplemented = ! in_array(GovernanceMappingStatus::NotFound, $statuses, true)
                && ! in_array(GovernanceMappingStatus::PartiallyImplemented, $statuses, true);

            $anyNotFound = in_array(GovernanceMappingStatus::NotFound, $statuses, true);

            $status = $allImplemented
                ? GovernanceMappingStatus::Implemented
                : GovernanceMappingStatus::PartiallyImplemented;

            $coverage[$table] = new GovernanceMappingResult(
                item_key: $table,
                item_label: "{$table} table-level coverage",
                owning_class: null,
                status: $status,
                notes: sprintf(
                    '%d/%d catalog fields Implemented for %s.%s',
                    count(array_filter($statuses, fn ($s) => $s === GovernanceMappingStatus::Implemented)),
                    count($statuses),
                    $table,
                    $anyNotFound ? ' At least one field is NotFound (representative-only field never built, or intentionally not represented) — see table() for exact field-level detail.' : ' Every non-Implemented field is represented by a relation/child table/JSON/service rather than a literal column.',
                ),
            );
        }

        return $coverage;
    }

    /**
     * Reuses Section 26 evidence (DataModelContractMappingService)
     * rather than reimplementing the whole UUID inventory.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function publicIdentifierCoverage(): array
    {
        $dataModelContract = new DataModelContractMappingService();
        $candidates = $dataModelContract->publicUuidCandidates();

        return [
            new GovernanceMappingResult(
                item_key: 'catalog_public_identifier_coverage',
                item_label: 'Public identifier (UUIDv7) coverage across catalog tables',
                owning_class: \App\Models\Concerns\HasPublicUuid::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: sprintf(
                    'HasPublicUuid backs the uuid column on the large majority of this catalog\'s tables (organizations, billing_accounts, firms, firm_settings, firm_licenses, seat_pools, firm_entitlements, communication_consents, firm_leads, clients, contacts, matters, parties, conflict_check_runs, documents, payment_plans, payment_plan_installments, payments, platform_invoices, license_files, document_templates, ai_usage_events all carry a real uuid column). Tasks and Deadlines — both named in this catalog — remain on DataModelContractMappingService::publicUuidCandidates() as decision-needed, not yet carrying a public uuid: %s. No column is added by this service; this is notes-only, reusing the Section 26 finding rather than re-deriving it.',
                    implode(', ', $candidates),
                ),
            ),
        ];
    }

    /**
     * Notes firm_id or transitive/organization-level scoping for every
     * catalog table.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function tenantBoundaryCoverage(): array
    {
        $results = [];

        foreach (self::TABLES as $table) {
            if ($table === 'activity_logs') {
                $results[] = new GovernanceMappingResult(
                    item_key: "{$table}.tenant_boundary",
                    item_label: "{$table} tenant boundary",
                    owning_class: \App\Models\SecurityEvent::class,
                    status: GovernanceMappingStatus::Implemented,
                    notes: 'No activity_logs table exists; SecurityEvent and TimelineEvent both carry a real firm_id column and apply firm-scoped querying.',
                );

                continue;
            }

            if (array_key_exists($table, self::GLOBAL_OR_ORGANIZATION_OWNED_TABLES)) {
                $results[] = new GovernanceMappingResult(
                    item_key: "{$table}.tenant_boundary",
                    item_label: "{$table} tenant boundary",
                    owning_class: \App\Models\Organization::class,
                    status: GovernanceMappingStatus::Implemented,
                    notes: "Deliberately carries no firm_id — {$table} is ".self::GLOBAL_OR_ORGANIZATION_OWNED_TABLES[$table].', sitting above or beside the firm tenancy boundary by design (matches DataModelContractMappingService::global_commercial_tables).',
                );

                continue;
            }

            if (array_key_exists($table, self::TRANSITIVELY_SCOPED_TABLES)) {
                $results[] = new GovernanceMappingResult(
                    item_key: "{$table}.tenant_boundary",
                    item_label: "{$table} tenant boundary",
                    owning_class: \App\Models\Concerns\BelongsToTenant::class,
                    status: GovernanceMappingStatus::Implemented,
                    notes: "No firm_id column of its own — scoped transitively via {$table} -> ".self::TRANSITIVELY_SCOPED_TABLES[$table].' (matches DataModelContractMappingService::firm_id_on_tenant_tables notes on matter_parties/task_dependencies).',
                );

                continue;
            }

            $results[] = new GovernanceMappingResult(
                item_key: "{$table}.tenant_boundary",
                item_label: "{$table} tenant boundary",
                owning_class: \App\Models\Concerns\BelongsToTenant::class,
                status: GovernanceMappingStatus::Implemented,
                notes: "{$table}.firm_id is a real column with BelongsToTenant applied to the owning model, giving direct tenant scoping.",
            );
        }

        return $results;
    }

    /**
     * Field findings that motivated a gap-register entry. Empty unless
     * a field-level finding here directly produced a
     * ComplianceGapRegistryService addition (trust_ledger_entries.posted_by
     * did, per the confirmed AWS finding that Reversal/ChargebackReversal
     * entries have no guaranteed actor trail).
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        $entry = $this->byKey('trust_ledger_entries.posted_by');

        return $entry ? [$entry] : [];
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function organizations(): array
    {
        $owning = \App\Models\Organization::class;
        $fields = [
            'id' => 'Real primary key.',
            'name' => 'Real, required column.',
            'legal_name' => 'Real, nullable column.',
            'status' => 'Real column, cast to RecordStatus.',
            'primary_contact' => 'Real, nullable column.',
            'default_plan_id' => 'Real FK to plans, added by the Phase 6 additive migration (deferred at Phase 1 per its own doc comment).',
            'conflict_scope' => 'Real column, cast to the real ConflictScope enum — drives ConflictCheckService scope resolution.',
            'created_at' => 'Real column via timestamps().',
            'updated_at' => 'Real column via timestamps().',
        ];

        return $this->buildTable('organizations', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function billingAccounts(): array
    {
        $owning = \App\Models\BillingAccount::class;

        return [
            'billing_accounts.id' => $this->result('billing_accounts', 'id', $owning, GovernanceMappingStatus::Implemented, 'Real primary key.'),
            'billing_accounts.organization_id' => $this->result('billing_accounts', 'organization_id', $owning, GovernanceMappingStatus::Implemented, 'Real FK to organizations, added by the Phase 6 additive migration.'),
            'billing_accounts.name' => $this->result('billing_accounts', 'name', $owning, GovernanceMappingStatus::Implemented, 'Real, required column.'),
            'billing_accounts.bill_to_contact' => $this->result('billing_accounts', 'bill_to_contact', $owning, GovernanceMappingStatus::Implemented, 'Real, nullable column, added by the Phase 6 additive migration.'),
            'billing_accounts.payment_method_ref' => $this->result('billing_accounts', 'payment_method_ref', $owning, GovernanceMappingStatus::Implemented, 'Real, nullable column, added by the Phase 6 additive migration. No payment processing is wired to it, but the reference column itself is real.'),
            'billing_accounts.billing_email' => $this->result('billing_accounts', 'billing_email', $owning, GovernanceMappingStatus::Implemented, 'Real, nullable column.'),
            'billing_accounts.consolidation_mode' => $this->result('billing_accounts', 'consolidation_mode', \App\Models\Organization::class, GovernanceMappingStatus::PartiallyImplemented, 'No consolidation_mode column on billing_accounts itself — it lives on organizations (cast to the real ConsolidationMode enum). The billing_accounts commercial-columns migration explicitly documents this as catalog imprecision, not a literal second column: the field is represented one level up in the same commercial hierarchy.'),
            'billing_accounts.status' => $this->result('billing_accounts', 'status', $owning, GovernanceMappingStatus::Implemented, 'Real column, cast to RecordStatus.'),
        ];
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firms(): array
    {
        $owning = \App\Models\Firm::class;
        $fields = [
            'id' => 'Real primary key.',
            'organization_id' => 'Real, nullable FK to organizations.',
            'billing_account_id' => 'Real, nullable FK to billing_accounts.',
            'name' => 'Real, required column.',
            'legal_name' => 'Real, nullable column.',
            'customer_type' => 'Real column, cast to the real CustomerType enum.',
            'deployment_mode' => 'Real column, cast to the real DeploymentMode enum, default saas — direct evidence for DeploymentModeCoverageMappingService.',
            'primary_country' => 'Real, nullable column.',
            'primary_state' => 'Real, nullable column.',
            'default_timezone' => 'Real column, default UTC.',
            'default_currency' => 'Real column (3-char), default USD.',
            'data_region' => 'Real, nullable column — confirmed present on firms.fillable and the firms migration (per approved decision, must be Implemented if present).',
            'status' => 'Cosmetic rename: firms.activation_status (draft/onboarding/activated) is the real column representing this field — license/subscription-level status lives separately on firm_licenses.license_status.',
            'created_at' => 'Real column via timestamps().',
            'updated_at' => 'Real column via timestamps().',
        ];

        return $this->buildTable('firms', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmSettings(): array
    {
        $owning = \App\Models\FirmSettings::class;

        $implementedFields = [
            'firm_id' => 'Real, unique FK to firms (one settings row per firm).',
            'payment_mode' => 'Real column, default operating_payments_only.',
            'trust_iolta_protection' => 'Real boolean column, default true — read by TrustEligibilityService.',
            'ai_mode' => 'Real column, cast to the real AiMode enum.',
            'client_2fa_mode' => 'Real column, default optional.',
            'portal_frontend_mode' => 'Real, nullable column.',
            'state_jurisdiction' => 'Real, nullable column.',
            'default_language' => 'Real column (10-char), default en.',
            'branding_settings_json' => 'Real, nullable JSON column.',
            'security_settings_json' => 'Real, nullable JSON column.',
        ];

        $result = $this->buildTable('firm_settings', $owning, $implementedFields, GovernanceMappingStatus::Implemented);

        $result['firm_settings.stripe_enabled'] = $this->result(
            'firm_settings',
            'stripe_enabled',
            \App\Services\EntitlementService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No stripe_enabled column exists on firm_settings (confirmed absent by direct migration inspection, and its own migration doc comment states so explicitly). Payment enablement is represented instead through the entitlement/module-catalog system: the real "payments" module_code row in module_catalog, gated per firm via firm_entitlements + EntitlementService, exactly as approved ("do not add a gap for stripe_enabled if entitlement/module-based").',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmLicenses(): array
    {
        $owning = \App\Models\FirmLicense::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'org_license_id' => 'Real, nullable FK to org_licenses, added by the Phase 6 additive migration.',
            'plan_id' => 'Real, nullable FK to plans, added by the Phase 6 additive migration.',
            'billing_account_id' => 'Real, nullable FK to billing_accounts.',
            'license_key' => 'Real, unique column.',
            'license_status' => 'Real column, default trial.',
            'deployment_mode' => 'Real, nullable column, added by the Phase 6 additive migration, reusing the existing DeploymentMode enum values.',
            'customer_type' => 'Real, nullable column, added by the Phase 6 additive migration, reusing the existing CustomerType enum values.',
            'billing_mode' => 'Real, nullable column, added by the Phase 6 additive migration.',
            'starts_at' => 'Real, nullable timestamp column.',
            'renews_at' => 'Real, nullable timestamp column.',
            'expires_at' => 'Real, nullable timestamp column.',
            'cancelled_at' => 'Real, nullable timestamp column.',
            'created_by' => 'Real, nullable FK to users.',
            'updated_by' => 'Real, nullable FK to users.',
        ];

        return $this->buildTable('firm_licenses', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function seatPools(): array
    {
        $owning = \App\Models\SeatPool::class;
        $fields = [
            'id' => 'Real primary key.',
            'organization_id' => 'Real FK to organizations.',
            'seat_class' => 'Real, required column.',
            'total_seats' => 'Real unsigned integer column.',
            'allocated_seats' => 'Real unsigned integer column, maintained as a running counter by SeatPoolService for O(1) exhaustion checks.',
            'counting_mode' => 'Real column, default named.',
            'period' => 'Real, nullable column.',
        ];

        return $this->buildTable('seat_pools', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function moduleCatalog(): array
    {
        $owning = \App\Models\ModuleCatalog::class;
        $fields = [
            'id' => 'Real primary key.',
            'module_code' => 'Real, unique column — the addressing key used everywhere modules are referenced.',
            'module_name' => 'Real, required column.',
            'category' => 'Real, nullable column.',
            'description' => 'Real, nullable text column.',
            'is_active' => 'Real boolean column, default true.',
            'requires_admin_approval' => 'Real boolean column, default false.',
        ];

        return $this->buildTable('module_catalog', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmEntitlements(): array
    {
        $owning = \App\Models\FirmEntitlement::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'module_code' => 'Real string FK to module_catalog.module_code.',
            'enabled' => 'Real boolean column, default false.',
            'source' => 'Real, required column — EntitlementService::resolve() picks the winner across sources by precedence.',
            'settings_json' => 'Real, nullable JSON column.',
            'starts_at' => 'Real, nullable timestamp column.',
            'ends_at' => 'Real, nullable timestamp column.',
            'created_at' => 'Real column via timestamps().',
            'updated_at' => 'Real column via timestamps().',
        ];

        return $this->buildTable('firm_entitlements', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function communicationConsents(): array
    {
        $owning = \App\Models\CommunicationConsent::class;

        $result = $this->buildTable('communication_consents', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'client_id' => 'Real, nullable FK to clients.',
            'channel' => 'Real column, cast to the real ConsentChannel enum.',
            'status' => 'Real column, cast to the real ConsentStatus enum.',
            'consent_text_version' => 'Real, required column.',
            'revoked_at' => 'Real, nullable timestamp column.',
        ], GovernanceMappingStatus::Implemented);

        $result['communication_consents.capture_method'] = $this->result('communication_consents', 'capture_method', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: communication_consents.captured_via is the real column representing this field.');
        $result['communication_consents.captured_at'] = $this->result('communication_consents', 'captured_at', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: communication_consents.granted_at is the real timestamp column set by ConsentService::capture() representing when consent was captured.');
        $result['communication_consents.captured_by'] = $this->result(
            'communication_consents',
            'captured_by',
            \App\Models\CommunicationConsentEvent::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No direct captured_by column on communication_consents. ConsentService::capture()/revoke() are the ONLY writers, and both create a paired CommunicationConsentEvent row in the same transaction with a real actor_user_id column — the exact actor who captured/revoked consent is guaranteed via this child event table, not a column on the consent row itself.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmLeads(): array
    {
        $owning = \App\Models\FirmLead::class;

        $result = $this->buildTable('firm_leads', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'name' => 'Real, required column.',
            'email' => 'Real, required column.',
            'phone' => 'Real, nullable column.',
            'status' => 'Real column, cast to the real FirmLeadStatus enum.',
            'assigned_to' => 'Real, nullable FK to users.',
            'converted_client_id' => 'Real, nullable FK — set ONLY by LeadConversionService (project rule: a lead must not silently become a client).',
            'created_at' => 'Real column via timestamps().',
        ], GovernanceMappingStatus::Implemented);

        $result['firm_leads.source_id'] = $this->result('firm_leads', 'source_id', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: firm_leads.lead_source_id is the real, nullable FK column representing this field.');
        $result['firm_leads.practice_area_interest'] = $this->result('firm_leads', 'practice_area_interest', $owning, GovernanceMappingStatus::Implemented, 'Represented as a real foreign key rather than a flat string: firm_leads.practice_area_interest_id, a nullable FK to practice_areas — a stronger representation than the catalog\'s representative flat-field name suggests.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function consultations(): array
    {
        $owning = \App\Models\Consultation::class;

        $result = $this->buildTable('consultations', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'scheduled_at' => 'Real, required timestamp column.',
            'held_at' => 'Real, nullable timestamp column.',
            'converted' => 'Real boolean column, default false.',
        ], GovernanceMappingStatus::Implemented);

        $result['consultations.lead_id'] = $this->result('consultations', 'lead_id', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: consultations.firm_lead_id is the real FK column representing this field.');
        $result['consultations.outcome'] = $this->result('consultations', 'outcome', \App\Models\ConsultationOutcome::class, GovernanceMappingStatus::Implemented, 'Represented as a real foreign key rather than a flat string: consultations.consultation_outcome_id, a nullable FK to consultation_outcomes.');
        $result['consultations.notes_ref'] = $this->result('consultations', 'notes_ref', $owning, GovernanceMappingStatus::PartiallyImplemented, 'consultations.notes is a real, direct nullable text column — the concept is represented, but as a plain inline field rather than a reference/pointer to a separate notes entity as the catalog\'s "_ref" naming suggests.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function clients(): array
    {
        $owning = \App\Models\Client::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'display_name' => 'Real, required column.',
            'legal_name' => 'Real, nullable column.',
            'email' => 'Real, required column.',
            'phone' => 'Real, nullable column.',
            'preferred_language' => 'Real column, default en.',
            'preferred_timezone' => 'Real column.',
            'portal_status' => 'Real column, cast to the real ClientPortalStatus enum.',
            'communication_preferences_id' => 'Real, nullable FK.',
            'created_by' => 'Real, nullable FK to users.',
        ];

        return $this->buildTable('clients', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function contacts(): array
    {
        $owning = \App\Models\Contact::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'client_id' => 'Real, nullable FK to clients — a contact may exist independent of any client.',
            'name' => 'Real, required column.',
            'company' => 'Real, nullable column.',
            'email' => 'Real, nullable column.',
            'phone' => 'Real, nullable column.',
            'role' => 'Real, nullable column.',
            'normalized_search_keys' => 'Real, nullable text column backing conflict-check matching.',
            'encrypted_sensitive_fields' => 'Real, nullable text column, reserved storage for future field-level encryption via EncryptionKeyService (not yet wired, by approved decision — the column exists so a later phase can populate it without a schema change).',
        ];

        return $this->buildTable('contacts', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function matters(): array
    {
        $owning = \App\Models\Matter::class;

        $result = $this->buildTable('matters', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'client_id' => 'Real FK to clients.',
            'primary_practice_area_id' => 'Real FK to practice_areas.',
            'matter_type_id' => 'Real FK to matter_types.',
            'status' => 'Real column, cast to the real MatterStatus enum.',
            'stage' => 'Real, nullable freeform string column — practice-area-template-driven, not a rigid state machine.',
            'assigned_attorney_id' => 'Real, nullable FK to users.',
            'opened_at' => 'Real, nullable timestamp column.',
            'closed_at' => 'Real, nullable timestamp column.',
        ], GovernanceMappingStatus::Implemented);

        $result['matters.billing_status'] = $this->result(
            'matters',
            'billing_status',
            \App\Models\Invoice::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No direct billing_status column on matters. It is derivable from real, matter-scoped data: Invoice.status and PaymentPlan.status both carry a matter_id foreign key, so a matter\'s billing state can be computed by aggregating them. No dedicated derivation service exists yet, but the underlying data fully supports it (approved: do not add a gap for this if derivable).',
        );
        $result['matters.readiness_score'] = $this->result(
            'matters',
            'readiness_score',
            \App\Models\MatterReadinessScore::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No literal numeric score column on matters. Represented by the MatterReadinessScore child table (one current row per matter, hasOne, recomputed in place by MatterReadinessService) via its status (NotReady/PartiallyReady/Ready) plus satisfied_count/total_count — a real, derived readiness signal, expressed as a status+ratio rather than a single score number.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function parties(): array
    {
        $owning = \App\Models\Party::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'name' => 'Real, required column.',
            'entity_type' => 'Real column, default individual — distinguishes individual vs. company parties (no separate companies table, per project rule).',
            'email' => 'Real, nullable column.',
            'phone' => 'Real, nullable column.',
            'company' => 'Real, nullable column.',
            'normalized_search_keys' => 'Real, nullable text column.',
            'notes' => 'Real, nullable text column.',
        ];

        return $this->buildTable('parties', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function matterParties(): array
    {
        $owning = \App\Models\MatterParty::class;
        $fields = [
            'id' => 'Real primary key.',
            'matter_id' => 'Real FK to matters.',
            'party_id' => 'Real FK to parties.',
            'relationship_type' => 'Real, nullable freeform string column — practice-area-template-driven (petitioner/beneficiary/opposing counsel/witness/...), not platform code.',
            'is_opposing' => 'Real boolean column, default false.',
            'is_related' => 'Real boolean column, default false.',
            'created_at' => 'Real column via timestamps().',
        ];

        return $this->buildTable('matter_parties', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function conflictCheckRuns(): array
    {
        $owning = \App\Models\ConflictCheckRun::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'matter_id' => 'Real FK to matters.',
            'requested_by' => 'Real, nullable FK to users.',
            'status' => 'Real column, default pending.',
            'searched_terms_json' => 'Real, nullable JSON column.',
            'scope' => 'Real column, default firm — resolves from Organization::conflict_scope at run time via ConflictCheckService.',
            'result_count' => 'Real unsigned integer column, default 0.',
            'completed_at' => 'Real, nullable timestamp column.',
        ];

        return $this->buildTable('conflict_check_runs', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function documents(): array
    {
        $owning = \App\Models\Document::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'matter_id' => 'Real, nullable FK to matters.',
            'client_id' => 'Real, nullable FK to clients.',
            'document_request_item_id' => 'Real, nullable FK to document_request_items.',
            'status' => 'Real column, cast to the real DocumentStatus enum.',
            'storage_path' => 'Real, required column — files are never stored in the database, only this pointer (project rule).',
            'file_hash' => 'Real, required column.',
            'mime_type' => 'Real, required column.',
            'size_bytes' => 'Real unsigned big integer column.',
            'encryption_key_id' => 'Real, nullable FK to tenant_encryption_keys.',
            'uploaded_by' => 'Real, nullable FK to users.',
            'approved_by' => 'Real, nullable FK to users.',
            'expires_at' => 'Real, nullable timestamp column.',
        ];

        return $this->buildTable('documents', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function tasks(): array
    {
        $owning = \App\Models\Task::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'matter_id' => 'Real, nullable FK to matters.',
            'assigned_to' => 'Real, nullable FK to users.',
            'title' => 'Real, required column.',
            'status' => 'Real column, cast to the real TaskStatus enum — Blocked/Overdue are both derived, never directly settable.',
            'priority' => 'Real column, cast to the real TaskPriority enum.',
            'due_at' => 'Real, nullable timestamp column.',
            'completed_at' => 'Real, nullable timestamp column.',
            'created_by' => 'Real, nullable FK to users.',
        ];

        return $this->buildTable('tasks', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function taskDependencies(): array
    {
        $owning = \App\Models\TaskDependency::class;
        $fields = [
            'id' => 'Real primary key.',
            'task_id' => 'Real FK to tasks.',
            'blocked_by_task_id' => 'Real FK to tasks — a database CHECK constraint rejects the trivial self-dependency case; TaskDependencyService rejects general cycles at write time.',
            'created_at' => 'Real column (useCurrent(), no updated_at — append-only join record).',
        ];

        return $this->buildTable('task_dependencies', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function deadlines(): array
    {
        $owning = \App\Models\Deadline::class;

        $result = $this->buildTable('deadlines', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'matter_id' => 'Real, nullable FK to matters.',
            'title' => 'Real, required column.',
            'deadline_type' => 'Real, required freeform string column — legal deadline types vary too much by practice area/jurisdiction to enumerate in the core schema.',
            'due_at' => 'Real, required timestamp column.',
            'jurisdiction' => 'Real, nullable column.',
            'source' => 'Real, nullable column.',
            'status' => 'Real column, cast to the real DeadlineStatus enum.',
        ], GovernanceMappingStatus::Implemented);

        $result['deadlines.reminder_policy_id'] = $this->result(
            'deadlines',
            'reminder_policy_id',
            $owning,
            GovernanceMappingStatus::PartiallyImplemented,
            'No reminder_policy_id column or reminder_policies table exists anywhere in the data contract (the migration\'s own doc comment names this exact dangling reference and the identical situation for payment_plans.dunning_policy_id). Represented instead by a real reminder_offsets_days JSON array column directly on the row (e.g. [7,3,1] days before due_at) — a deliberate, documented architectural substitution, not a missing feature.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function paymentPlans(): array
    {
        $owning = \App\Models\PaymentPlan::class;

        $result = $this->buildTable('payment_plans', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'client_id' => 'Real FK to clients.',
            'matter_id' => 'Real, nullable FK to matters.',
            'invoice_id' => 'Real, nullable FK to invoices.',
            'total_cents' => 'Real unsigned integer column.',
            'currency' => 'Real column (3-char), default usd.',
            'status' => 'Real column, cast to the real PaymentPlanStatus enum.',
            'installment_count' => 'Real unsigned integer column.',
            'created_by' => 'Real, nullable FK to users.',
        ], GovernanceMappingStatus::Implemented);

        $result['payment_plans.dunning_policy_id'] = $this->result(
            'payment_plans',
            'dunning_policy_id',
            \App\Services\PaymentPlanDunningService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No dunning_policy_id column or dunning_policies table exists anywhere in the data contract (the migration\'s own doc comment names this exact dangling reference). PaymentPlanDunningService applies one fixed default policy in code instead of a speculative table — a deliberate, documented architectural substitution, not a missing feature.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function paymentPlanInstallments(): array
    {
        $owning = \App\Models\PaymentPlanInstallment::class;

        $result = $this->buildTable('payment_plan_installments', $owning, [
            'id' => 'Real primary key.',
            'payment_plan_id' => 'Real FK to payment_plans.',
            'sequence' => 'Real unsigned integer column, unique per plan.',
            'amount_cents' => 'Real unsigned integer column.',
            'due_at' => 'Real, required timestamp column.',
            'status' => 'Real column, cast to the real PaymentPlanInstallmentStatus enum.',
            'dunning_state' => 'Real, nullable plain string column.',
        ], GovernanceMappingStatus::Implemented);

        $result['payment_plan_installments.paid_payment_id'] = $this->result(
            'payment_plan_installments',
            'paid_payment_id',
            \App\Models\Payment::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No single direct paid_payment_id column. Represented by two real mechanisms instead: paid_amount_cents, a cache recomputed exclusively by PaymentApplicationService from the canonical payments table, and the reverse relation — every Payment row carries payment_plan_installment_id, so every payment applied to this installment is discoverable via payments.payment_plan_installment_id rather than a single forward FK on the installment.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function payments(): array
    {
        $owning = \App\Models\Payment::class;

        $result = $this->buildTable('payments', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'client_id' => 'Real FK to clients.',
            'matter_id' => 'Real, nullable FK to matters.',
            'invoice_id' => 'Real, nullable FK to invoices.',
            'amount_cents' => 'Real unsigned integer column.',
            'currency' => 'Real column (3-char), default usd.',
            'payment_method' => 'Real column, cast to the real ManualPaymentMethod enum.',
            'payment_classification' => 'Real column, cast to the real, strict PaymentClassification enum — set only by PaymentClassificationService.',
            'status' => 'Real column, cast to the real PaymentStatus enum.',
            'external_reference' => 'Real, nullable column.',
            'idempotency_key' => 'Real, nullable column — backed by a partial unique index (firm_id, idempotency_key) as a database-level double-submission backstop, direct evidence for IdempotencyKeyCoverageMappingService::byKey(\'payment_collection\').',
            'recorded_by' => 'Real, nullable FK to users.',
        ], GovernanceMappingStatus::Implemented);

        $result['payments.installment_id'] = $this->result('payments', 'installment_id', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: payments.payment_plan_installment_id is the real, nullable FK column representing this field.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function platformInvoices(): array
    {
        $owning = \App\Models\PlatformInvoice::class;

        $result = $this->buildTable('platform_invoices', $owning, [
            'id' => 'Real primary key.',
            'billing_account_id' => 'Real FK to billing_accounts.',
            'platform_subscription_id' => 'Real, nullable FK to platform_subscriptions.',
            'status' => 'Real column, cast to the real PlatformInvoiceStatus enum.',
            'due_at' => 'Real, nullable timestamp column.',
            'paid_at' => 'Real, nullable timestamp column.',
        ], GovernanceMappingStatus::Implemented);

        $result['platform_invoices.amount_cents'] = $this->result('platform_invoices', 'amount_cents', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename/enhancement: platform_invoices.total_cents is the real column, accompanied by subtotal_cents/tax_cents for a more structured breakdown than a single flat amount.');
        $result['platform_invoices.currency'] = $this->result('platform_invoices', 'currency', $owning, GovernanceMappingStatus::NotFound, 'No currency column exists on platform_invoices, and no currency column exists on billing_accounts either (confirmed by direct inspection of both models\' fillable). Platform billing is implicitly single-currency today. Not added as a gap per this section\'s scope (no new gap items except the confirmed trust-ledger actor gap).');
        $result['platform_invoices.usage_attribution_json'] = $this->result(
            'platform_invoices',
            'usage_attribution_json',
            \App\Models\UsageRollup::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No usage_attribution_json column on platform_invoices. Represented instead by the real usage_rollups table: keyed to the same billing_account_id, with an optional per-firm attribution column and metric/period/quantity/unit — a structured relational representation of usage attribution rather than an embedded JSON blob (approved: do not add a gap for this since usage_rollups covers it).',
        );
        $result['platform_invoices.issued_at'] = $this->result('platform_invoices', 'issued_at', $owning, GovernanceMappingStatus::PartiallyImplemented, 'No exact issued_at column. platform_invoices.period_starts_at/period_ends_at (the billing period) plus the standard created_at timestamp together represent when the invoice was generated, though no single column is named issued_at.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function licenseFiles(): array
    {
        $owning = \App\Models\LicenseFile::class;

        $result = $this->buildTable('license_files', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real, nullable FK to firms (one of exactly two mutually-exclusive owner paths, enforced by a database CHECK constraint).',
            'organization_id' => 'Real, nullable FK to organizations (the other of the two mutually-exclusive owner paths).',
            'signed_payload' => 'Real, required text column.',
            'issued_at' => 'Real, required timestamp column.',
            'expires_at' => 'Real, required timestamp column.',
            'issued_by' => 'Real FK to users.',
        ], GovernanceMappingStatus::Implemented);

        $result['license_files.signature_alg'] = $this->result('license_files', 'signature_alg', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: license_files.signature_algorithm is the real column representing this field.');
        $result['license_files.grace_days'] = $this->result('license_files', 'grace_days', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: license_files.grace_period_days is the real, unsigned integer column representing this field.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function trustLedgerEntries(): array
    {
        $owning = \App\Models\TrustLedgerEntry::class;

        $result = $this->buildTable('trust_ledger_entries', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'matter_id' => 'Real, nullable FK to matters.',
            'entry_type' => 'Real column, cast to the real, closed TrustLedgerEntryType enum (Deposit/WithdrawalToInvoice/Refund/ChargebackReversal/Adjustment/Reversal).',
            'amount_cents' => 'Real signed big integer column.',
            'posted_at' => 'Real timestamp column (useCurrent()) — the row has $timestamps = false; only posted_at exists, and the model\'s own booted() guard throws on any update/delete (append-only, enforced in code).',
        ], GovernanceMappingStatus::Implemented);

        $result['trust_ledger_entries.trust_account_id'] = $this->result('trust_ledger_entries', 'trust_account_id', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: trust_ledger_entries.trust_ledger_id is the real FK column representing this field (TrustLedger is the trust-account concept per firm/client).');
        $result['trust_ledger_entries.balance_after_cents'] = $this->result(
            'trust_ledger_entries',
            'balance_after_cents',
            \App\Models\MatterTrustBalance::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No per-entry balance_after_cents column (the row is a pure append-only evidentiary entry, mirroring SignatureEvent/DocumentHash). A running balance is maintained instead by TrustBalanceService, which recomputes and stores balance_cents on the real MatterTrustBalance/TrustLedger tables after every posting — the balance concept is real and current, just not frozen per-entry alongside the entry itself.',
        );
        $result['trust_ledger_entries.reference_type'] = $this->result(
            'trust_ledger_entries',
            'reference_type',
            $owning,
            GovernanceMappingStatus::PartiallyImplemented,
            'No generic polymorphic reference_type/reference_id pair. Represented instead by four separate, strongly-typed nullable FK columns — trust_approval_event_id, trust_transfer_request_id, trust_refund_request_id, source_payment_id — a more type-safe mechanism than a generic polymorphic reference achieving the same "trace back to the authorizing record" purpose.',
        );
        $result['trust_ledger_entries.reference_id'] = $this->result(
            'trust_ledger_entries',
            'reference_id',
            $owning,
            GovernanceMappingStatus::PartiallyImplemented,
            'Same finding as reference_type: represented by the four separate typed FK columns rather than one generic polymorphic id column.',
        );
        $result['trust_ledger_entries.reversal_of_id'] = $this->result('trust_ledger_entries', 'reversal_of_id', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: trust_ledger_entries.reverses_entry_id is the real, nullable, self-referencing FK column representing this field.');
        $result['trust_ledger_entries.posted_by'] = $this->result(
            'trust_ledger_entries',
            'posted_by',
            \App\Services\TrustLedgerEntryReversalService::class,
            GovernanceMappingStatus::NotFound,
            'No direct posted_by column exists on trust_ledger_entries (confirmed by direct migration/model inspection). For Deposit/WithdrawalToInvoice/Refund/Adjustment entries, an actor IS guaranteed indirectly: every such row must carry trust_approval_event_id, trust_transfer_request_id, or trust_refund_request_id, each pointing to a row with a real actor column (TrustApprovalEvent.actor_firm_user_id; TrustTransferRequest/TrustRefundRequest.requested_by_firm_user_id/approved_by_firm_user_id). For Reversal/ChargebackReversal entries, NO guaranteed actor trail exists: TrustLedgerEntryReversalService::reverse() sets none of those three FKs (only reverses_entry_id), takes no actor parameter, and its only caller, TrustChargebackService::reverse(), requires a FirmUser for authorization but never persists that actor anywhere — TrustChargebackEvent has no reported_by/reversed_by/resolved_by column. This confirmed gap is registered as trust_ledger_entry_posting_actor_not_guaranteed (High, ComplianceGapRegistryService).',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function documentTemplates(): array
    {
        $owning = \App\Models\DocumentTemplate::class;

        $result = $this->buildTable('document_templates', $owning, [
            'id' => 'Real primary key.',
            'firm_id' => 'Real, nullable FK to firms (null = global platform default, set = firm-specific override).',
            'name' => 'Real, required column.',
            'status' => 'Real column, cast to the real DocumentTemplateStatus enum.',
        ], GovernanceMappingStatus::Implemented);

        $result['document_templates.template_pack_id'] = $this->result('document_templates', 'template_pack_id', $owning, GovernanceMappingStatus::NotFound, 'No template_pack_id FK exists on document_templates — it is addressed by its own unique template_code instead, unrelated to the TemplatePack catalog (confirmed genuinely absent; not added as a gap per this section\'s scope).');
        $result['document_templates.kind'] = $this->result('document_templates', 'kind', $owning, GovernanceMappingStatus::NotFound, 'No kind column exists. document_templates.category (cast to DocumentTemplateCategory, default miscellaneous) is a real but distinct concept, not a rename of "kind" — confirmed genuinely absent per approved decision (do not add a gap for document_templates.kind).');
        $result['document_templates.version'] = $this->result('document_templates', 'version', \App\Models\DocumentTemplateVersion::class, GovernanceMappingStatus::PartiallyImplemented, 'No version column on document_templates itself. Represented by the real document_template_versions child table (versions() HasMany relation) via its version_label/status/content_status columns.');
        $result['document_templates.field_map_json'] = $this->result('document_templates', 'field_map_json', \App\Models\DocumentTemplateVersion::class, GovernanceMappingStatus::PartiallyImplemented, 'No field_map_json column on document_templates itself. Represented by document_template_versions.merge_fields_schema, a real JSON-cast array column on the version child table serving the same merge-field-mapping purpose.');
        $result['document_templates.review_rules_json'] = $this->result('document_templates', 'review_rules_json', $owning, GovernanceMappingStatus::NotFound, 'No review_rules_json column exists on document_templates or document_template_versions (confirmed by direct inspection of both models\' fillable) — genuinely absent per approved decision (do not add a gap for document_templates.review_rules_json).');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function formEditionWatchItems(): array
    {
        $owning = \App\Models\FormEditionWatchItem::class;

        $result = $this->buildTable('form_edition_watch_items', $owning, [
            'id' => 'Real primary key.',
            'form_template_id' => 'Real FK to form_templates.',
        ], GovernanceMappingStatus::Implemented);

        $result['form_edition_watch_items.authority'] = $this->result('form_edition_watch_items', 'authority', $owning, GovernanceMappingStatus::NotFound, 'No authority column exists (confirmed genuinely absent) — a low-stakes internal content-ops helper field, not added as a gap per approved decision.');
        $result['form_edition_watch_items.current_edition'] = $this->result('form_edition_watch_items', 'current_edition', $owning, GovernanceMappingStatus::NotFound, 'No current_edition column exists (confirmed genuinely absent) — not added as a gap per approved decision.');
        $result['form_edition_watch_items.detected_edition'] = $this->result('form_edition_watch_items', 'detected_edition', $owning, GovernanceMappingStatus::PartiallyImplemented, 'form_edition_watch_items.detected_edition_date is a real, nullable string column overlapping this concept (though it stores a date string rather than a pure edition label).');
        $result['form_edition_watch_items.detected_at'] = $this->result('form_edition_watch_items', 'detected_at', $owning, GovernanceMappingStatus::PartiallyImplemented, 'No dedicated detected_at timestamp; form_edition_watch_items.detected_edition_date is the closest real, overlapping column recording when a new edition was detected.');
        $result['form_edition_watch_items.sla_due_at'] = $this->result('form_edition_watch_items', 'sla_due_at', $owning, GovernanceMappingStatus::NotFound, 'No sla_due_at column exists (confirmed genuinely absent) — a low-stakes internal content-ops helper field, not added as a gap per approved decision.');
        $result['form_edition_watch_items.status'] = $this->result('form_edition_watch_items', 'status', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: form_edition_watch_items.watch_status is the real column representing this field.');
        $result['form_edition_watch_items.action_taken'] = $this->result('form_edition_watch_items', 'action_taken', $owning, GovernanceMappingStatus::NotFound, 'No action_taken column exists (confirmed genuinely absent). A free-text notes column exists for unstructured context, but does not represent a structured action_taken field — not added as a gap per approved decision.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function aiUsageEvents(): array
    {
        $owning = \App\Models\AiUsageEvent::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'user_id' => 'Real FK to users.',
            'matter_id' => 'Real, nullable FK to matters (some AI actions, e.g. a firm-wide summary, are not matter-scoped).',
            'ai_mode' => 'Real, required column.',
            'provider' => 'Real, required column.',
            'model' => 'Real, required column.',
            'tokens_in' => 'Real unsigned big integer column, default 0.',
            'tokens_out' => 'Real unsigned big integer column, default 0.',
            'cost_cents' => 'Real unsigned big integer column, default 0 — metadata only, never written to platform_invoices/payments (project rule).',
            'approval_required' => 'Real boolean column, default false.',
            'action_type' => 'Real, required column.',
            'created_at' => 'Real timestamp column (useCurrent()) — append-only.',
        ];

        return $this->buildTable('ai_usage_events', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function tenantEncryptionKeys(): array
    {
        $owning = \App\Models\TenantEncryptionKey::class;
        $fields = [
            'id' => 'Real primary key.',
            'firm_id' => 'Real FK to firms.',
            'key_version' => 'Real unsigned integer column, default 1.',
            'status' => 'Real column, default active — a partial unique index enforces at most one active key per firm at the database layer.',
            'created_at' => 'Real column via timestamps().',
            'destroyed_at' => 'Real, nullable timestamp column.',
            'destruction_request_id' => 'Real, nullable unsigned big integer column (no FK yet: key_destruction_requests does not exist).',
        ];

        return $this->buildTable('tenant_encryption_keys', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * activity_logs does not exist and is NOT created by this package —
     * matches DataModelContractMappingService::activityLogsInterpretation()
     * exactly, reused here rather than re-derived.
     *
     * @return array<string, GovernanceMappingResult>
     */
    private function activityLogs(): array
    {
        $securityEvent = \App\Models\SecurityEvent::class;
        $timelineEvent = \App\Models\TimelineEvent::class;

        return [
            'activity_logs.id' => $this->result('activity_logs', 'id', $securityEvent, GovernanceMappingStatus::Implemented, 'No activity_logs table exists. SecurityEvent and TimelineEvent both carry a real primary key.'),
            'activity_logs.firm_id' => $this->result('activity_logs', 'firm_id', $securityEvent, GovernanceMappingStatus::Implemented, 'Both SecurityEvent and TimelineEvent carry a real, required firm_id column.'),
            'activity_logs.actor_type' => $this->result('activity_logs', 'actor_type', $securityEvent, GovernanceMappingStatus::Implemented, 'Both SecurityEvent.actor_type and TimelineEvent.actor_type are real columns.'),
            'activity_logs.actor_id' => $this->result('activity_logs', 'actor_id', $securityEvent, GovernanceMappingStatus::Implemented, 'Both SecurityEvent.actor_id and TimelineEvent.actor_id are real columns.'),
            'activity_logs.event_type' => $this->result('activity_logs', 'event_type', $securityEvent, GovernanceMappingStatus::Implemented, 'Both SecurityEvent.event_type and TimelineEvent.event_type are real columns.'),
            'activity_logs.category' => $this->result('activity_logs', 'category', $securityEvent, GovernanceMappingStatus::Implemented, 'SecurityEvent.category is a real column; TimelineEvent does not carry a separate category, using event_type for this purpose instead.'),
            'activity_logs.subject_type' => $this->result('activity_logs', 'subject_type', $timelineEvent, GovernanceMappingStatus::Implemented, 'TimelineEvent.subject_type is a real column (the general firm activity narrative primitive); SecurityEvent does not carry a separate subject reference, being scoped to security/access events specifically.'),
            'activity_logs.subject_id' => $this->result('activity_logs', 'subject_id', $timelineEvent, GovernanceMappingStatus::Implemented, 'TimelineEvent.subject_id is a real column.'),
            'activity_logs.ip_address' => $this->result('activity_logs', 'ip_address', $securityEvent, GovernanceMappingStatus::Implemented, 'SecurityEvent.ip_address is a real column.'),
            'activity_logs.user_agent' => $this->result('activity_logs', 'user_agent', $securityEvent, GovernanceMappingStatus::Implemented, 'SecurityEvent.user_agent is a real column.'),
            'activity_logs.metadata_json' => $this->result('activity_logs', 'metadata_json', $timelineEvent, GovernanceMappingStatus::Implemented, 'Cosmetic rename: TimelineEvent.metadata_json is a real JSON-cast column; SecurityEvent.metadata (cast to array) is the equivalent column on the security-event primitive.'),
            'activity_logs.created_at' => $this->result('activity_logs', 'created_at', $securityEvent, GovernanceMappingStatus::Implemented, 'Both SecurityEvent and TimelineEvent carry a real created_at column.'),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, GovernanceMappingResult>
     */
    private function buildTable(string $table, ?string $owningClass, array $fields, GovernanceMappingStatus $status): array
    {
        $result = [];

        foreach ($fields as $field => $note) {
            $result["{$table}.{$field}"] = $this->result($table, $field, $owningClass, $status, $note);
        }

        return $result;
    }

    private function result(string $table, string $field, ?string $owningClass, GovernanceMappingStatus $status, string $notes): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: "{$table}.{$field}",
            item_label: "{$table}.{$field}",
            owning_class: $owningClass,
            status: $status,
            notes: $notes,
        );
    }
}
