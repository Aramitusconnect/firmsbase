<?php

namespace Tests\Feature\Governance\EntityFieldCatalog;

use App\Enums\GovernanceMappingStatus;
use App\Services\EntityFieldCatalogMappingService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class EntityFieldCatalogMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'organizations.id', 'organizations.name', 'organizations.legal_name', 'organizations.status',
        'organizations.primary_contact', 'organizations.default_plan_id', 'organizations.conflict_scope',
        'organizations.created_at', 'organizations.updated_at',

        'billing_accounts.id', 'billing_accounts.organization_id', 'billing_accounts.name',
        'billing_accounts.bill_to_contact', 'billing_accounts.payment_method_ref', 'billing_accounts.billing_email',
        'billing_accounts.consolidation_mode', 'billing_accounts.status',

        'firms.id', 'firms.organization_id', 'firms.billing_account_id', 'firms.name', 'firms.legal_name',
        'firms.customer_type', 'firms.deployment_mode', 'firms.primary_country', 'firms.primary_state',
        'firms.default_timezone', 'firms.default_currency', 'firms.data_region', 'firms.status',
        'firms.created_at', 'firms.updated_at',

        'firm_settings.firm_id', 'firm_settings.payment_mode', 'firm_settings.trust_iolta_protection',
        'firm_settings.stripe_enabled', 'firm_settings.ai_mode', 'firm_settings.client_2fa_mode',
        'firm_settings.portal_frontend_mode', 'firm_settings.state_jurisdiction', 'firm_settings.default_language',
        'firm_settings.branding_settings_json', 'firm_settings.security_settings_json',

        'firm_licenses.id', 'firm_licenses.firm_id', 'firm_licenses.org_license_id', 'firm_licenses.plan_id',
        'firm_licenses.billing_account_id', 'firm_licenses.license_key', 'firm_licenses.license_status',
        'firm_licenses.deployment_mode', 'firm_licenses.customer_type', 'firm_licenses.billing_mode',
        'firm_licenses.starts_at', 'firm_licenses.renews_at', 'firm_licenses.expires_at',
        'firm_licenses.cancelled_at', 'firm_licenses.created_by', 'firm_licenses.updated_by',

        'seat_pools.id', 'seat_pools.organization_id', 'seat_pools.seat_class', 'seat_pools.total_seats',
        'seat_pools.allocated_seats', 'seat_pools.counting_mode', 'seat_pools.period',

        'module_catalog.id', 'module_catalog.module_code', 'module_catalog.module_name',
        'module_catalog.category', 'module_catalog.description', 'module_catalog.is_active',
        'module_catalog.requires_admin_approval',

        'firm_entitlements.id', 'firm_entitlements.firm_id', 'firm_entitlements.module_code',
        'firm_entitlements.enabled', 'firm_entitlements.source', 'firm_entitlements.settings_json',
        'firm_entitlements.starts_at', 'firm_entitlements.ends_at', 'firm_entitlements.created_at',
        'firm_entitlements.updated_at',

        'communication_consents.id', 'communication_consents.firm_id', 'communication_consents.client_id',
        'communication_consents.channel', 'communication_consents.status',
        'communication_consents.consent_text_version', 'communication_consents.capture_method',
        'communication_consents.captured_at', 'communication_consents.revoked_at',
        'communication_consents.captured_by',

        'firm_leads.id', 'firm_leads.firm_id', 'firm_leads.source_id', 'firm_leads.name', 'firm_leads.email',
        'firm_leads.phone', 'firm_leads.practice_area_interest', 'firm_leads.status', 'firm_leads.assigned_to',
        'firm_leads.converted_client_id', 'firm_leads.created_at',

        'consultations.id', 'consultations.firm_id', 'consultations.lead_id', 'consultations.scheduled_at',
        'consultations.held_at', 'consultations.outcome', 'consultations.notes_ref', 'consultations.converted',

        'clients.id', 'clients.firm_id', 'clients.display_name', 'clients.legal_name', 'clients.email',
        'clients.phone', 'clients.preferred_language', 'clients.preferred_timezone', 'clients.portal_status',
        'clients.communication_preferences_id', 'clients.created_by',

        'contacts.id', 'contacts.firm_id', 'contacts.client_id', 'contacts.name', 'contacts.company',
        'contacts.email', 'contacts.phone', 'contacts.role', 'contacts.normalized_search_keys',
        'contacts.encrypted_sensitive_fields',

        'matters.id', 'matters.firm_id', 'matters.client_id', 'matters.primary_practice_area_id',
        'matters.matter_type_id', 'matters.status', 'matters.stage', 'matters.assigned_attorney_id',
        'matters.opened_at', 'matters.closed_at', 'matters.billing_status', 'matters.readiness_score',

        'parties.id', 'parties.firm_id', 'parties.name', 'parties.entity_type', 'parties.email',
        'parties.phone', 'parties.company', 'parties.normalized_search_keys', 'parties.notes',

        'matter_parties.id', 'matter_parties.matter_id', 'matter_parties.party_id',
        'matter_parties.relationship_type', 'matter_parties.is_opposing', 'matter_parties.is_related',
        'matter_parties.created_at',

        'conflict_check_runs.id', 'conflict_check_runs.firm_id', 'conflict_check_runs.matter_id',
        'conflict_check_runs.requested_by', 'conflict_check_runs.status',
        'conflict_check_runs.searched_terms_json', 'conflict_check_runs.scope',
        'conflict_check_runs.result_count', 'conflict_check_runs.completed_at',

        'documents.id', 'documents.firm_id', 'documents.matter_id', 'documents.client_id',
        'documents.document_request_item_id', 'documents.status', 'documents.storage_path',
        'documents.file_hash', 'documents.mime_type', 'documents.size_bytes', 'documents.encryption_key_id',
        'documents.uploaded_by', 'documents.approved_by', 'documents.expires_at',

        'tasks.id', 'tasks.firm_id', 'tasks.matter_id', 'tasks.assigned_to', 'tasks.title', 'tasks.status',
        'tasks.priority', 'tasks.due_at', 'tasks.completed_at', 'tasks.created_by',

        'task_dependencies.id', 'task_dependencies.task_id', 'task_dependencies.blocked_by_task_id',
        'task_dependencies.created_at',

        'deadlines.id', 'deadlines.firm_id', 'deadlines.matter_id', 'deadlines.title',
        'deadlines.deadline_type', 'deadlines.due_at', 'deadlines.jurisdiction', 'deadlines.source',
        'deadlines.reminder_policy_id', 'deadlines.status',

        'payment_plans.id', 'payment_plans.firm_id', 'payment_plans.client_id', 'payment_plans.matter_id',
        'payment_plans.invoice_id', 'payment_plans.total_cents', 'payment_plans.currency',
        'payment_plans.status', 'payment_plans.installment_count', 'payment_plans.dunning_policy_id',
        'payment_plans.created_by',

        'payment_plan_installments.id', 'payment_plan_installments.payment_plan_id',
        'payment_plan_installments.sequence', 'payment_plan_installments.amount_cents',
        'payment_plan_installments.due_at', 'payment_plan_installments.status',
        'payment_plan_installments.paid_payment_id', 'payment_plan_installments.dunning_state',

        'payments.id', 'payments.firm_id', 'payments.client_id', 'payments.matter_id', 'payments.invoice_id',
        'payments.installment_id', 'payments.amount_cents', 'payments.currency', 'payments.payment_method',
        'payments.payment_classification', 'payments.status', 'payments.external_reference',
        'payments.idempotency_key', 'payments.recorded_by',

        'platform_invoices.id', 'platform_invoices.billing_account_id',
        'platform_invoices.platform_subscription_id', 'platform_invoices.amount_cents',
        'platform_invoices.currency', 'platform_invoices.status', 'platform_invoices.usage_attribution_json',
        'platform_invoices.issued_at', 'platform_invoices.due_at', 'platform_invoices.paid_at',

        'license_files.id', 'license_files.firm_id', 'license_files.organization_id',
        'license_files.signed_payload', 'license_files.signature_alg', 'license_files.issued_at',
        'license_files.expires_at', 'license_files.grace_days', 'license_files.issued_by',

        'trust_ledger_entries.id', 'trust_ledger_entries.firm_id', 'trust_ledger_entries.trust_account_id',
        'trust_ledger_entries.matter_id', 'trust_ledger_entries.entry_type', 'trust_ledger_entries.amount_cents',
        'trust_ledger_entries.balance_after_cents', 'trust_ledger_entries.reference_type',
        'trust_ledger_entries.reference_id', 'trust_ledger_entries.reversal_of_id',
        'trust_ledger_entries.posted_by', 'trust_ledger_entries.posted_at',

        'document_templates.id', 'document_templates.firm_id', 'document_templates.template_pack_id',
        'document_templates.name', 'document_templates.kind', 'document_templates.version',
        'document_templates.field_map_json', 'document_templates.review_rules_json',
        'document_templates.status',

        'form_edition_watch_items.id', 'form_edition_watch_items.form_template_id',
        'form_edition_watch_items.authority', 'form_edition_watch_items.current_edition',
        'form_edition_watch_items.detected_edition', 'form_edition_watch_items.detected_at',
        'form_edition_watch_items.sla_due_at', 'form_edition_watch_items.status',
        'form_edition_watch_items.action_taken',

        'ai_usage_events.id', 'ai_usage_events.firm_id', 'ai_usage_events.user_id',
        'ai_usage_events.matter_id', 'ai_usage_events.ai_mode', 'ai_usage_events.provider',
        'ai_usage_events.model', 'ai_usage_events.tokens_in', 'ai_usage_events.tokens_out',
        'ai_usage_events.cost_cents', 'ai_usage_events.approval_required', 'ai_usage_events.action_type',
        'ai_usage_events.created_at',

        'tenant_encryption_keys.id', 'tenant_encryption_keys.firm_id', 'tenant_encryption_keys.key_version',
        'tenant_encryption_keys.status', 'tenant_encryption_keys.created_at',
        'tenant_encryption_keys.destroyed_at', 'tenant_encryption_keys.destruction_request_id',

        'activity_logs.id', 'activity_logs.firm_id', 'activity_logs.actor_type', 'activity_logs.actor_id',
        'activity_logs.event_type', 'activity_logs.category', 'activity_logs.subject_type',
        'activity_logs.subject_id', 'activity_logs.ip_address', 'activity_logs.user_agent',
        'activity_logs.metadata_json', 'activity_logs.created_at',
    ];

    private EntityFieldCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityFieldCatalogMappingService();
    }

    public function test_all_listed_table_field_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required catalog key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate catalog key(s) found.');
    }

    public function test_all_entries_return_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
        }
    }

    public function test_every_entry_has_notes_or_evidence(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
        }
    }

    public function test_data_region_is_implemented(): void
    {
        $item = $this->service->byKey('firms.data_region');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
    }

    public function test_activity_logs_representation_notes_mention_actual_table_or_service(): void
    {
        foreach ($this->service->table('activity_logs') as $key => $item) {
            $mentionsRealPrimitive = str_contains($item->notes, 'SecurityEvent') || str_contains($item->notes, 'TimelineEvent');

            $this->assertTrue($mentionsRealPrimitive, "activity_logs field {$key} should mention SecurityEvent or TimelineEvent since no activity_logs table exists.");
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.field'));
    }

    public function test_table_returns_only_fields_for_the_requested_table(): void
    {
        $matters = $this->service->table('matters');

        $this->assertNotEmpty($matters);
        foreach (array_keys($matters) as $key) {
            $this->assertStringStartsWith('matters.', $key);
        }
    }

    public function test_tables_returns_all_thirty_two_catalog_tables(): void
    {
        $this->assertCount(32, $this->service->tables());
        $this->assertContains('trust_ledger_entries', $this->service->tables());
        $this->assertContains('activity_logs', $this->service->tables());
    }
}
