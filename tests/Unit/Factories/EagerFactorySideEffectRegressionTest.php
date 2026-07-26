<?php

namespace Tests\Unit\Factories;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Models\IntegrationWebhookReceipt;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Models\AccountingExportBatch;
use App\Models\AccountingExportLine;
use App\Models\AiApprovalEvent;
use App\Models\AiApprovalRequest;
use App\Models\AiToolAction;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Models\ConflictCheckRun;
use App\Models\Consultation;
use App\Models\DeletionRequest;
use App\Models\DocumentChaseEvent;
use App\Models\DocumentHash;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseReceipt;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\FirmEntitlement;
use App\Models\FirmEntitlementEvent;
use App\Models\FirmLead;
use App\Models\FirmLicense;
use App\Models\FormDraft;
use App\Models\FormReviewEvent;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentEvent;
use App\Models\InstalledTemplatePack;
use App\Models\IntakeSubmission;
use App\Models\Invoice;
use App\Models\LicenseFile;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Models\MatterReadinessScore;
use App\Models\Payment;
use App\Models\PaymentClassificationEvent;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanEvent;
use App\Models\ReadinessScoreEvent;
use App\Models\SignatureCertificate;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradeLog;
use App\Models\TemplateUpgradePreview;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for the eager-factory-side-effects audit (deferred
 * from the FirmsVault admin control center's Phase 1 test-isolation fix,
 * commit b440e58 — that fix flagged, but explicitly did not touch,
 * "6 other factories [that] share the same eager-create anti-pattern
 * shape"). This audit found the anti-pattern was materially wider than
 * that estimate: 42 factories built a foreign key (or a sibling column
 * that must agree with it) by calling ->create() as a plain PHP
 * statement — or as a bare array value never wrapped in a lazy
 * closure/Factory instance — at the top of definition(), so the side
 * effect fired unconditionally even when a later state()/forXxx()
 * override, or a direct create([...]) attribute override, replaced
 * that same key in the final row. Every one of those calls silently
 * created and discarded a real, committed row (almost always rooted in
 * an orphaned Firm, since every chain here ultimately resolves back to
 * one).
 *
 * Each test below proves the leak existed before the fix and is gone
 * after it: build the override target through an explicit, independent
 * chain, snapshot Firm::count() immediately before invoking the
 * factory under test with an overriding state (mirroring how each
 * factory is actually used elsewhere in this codebase — a named
 * forXxx() state or a direct create([...]) attribute override), then
 * assert Firm::count() is UNCHANGED afterward. Firm has no RLS of its
 * own (root tenant table), so it is a safe, always-readable leak
 * indicator regardless of which downstream table is FORCE-RLS
 * protected. Run against the pre-fix code (git stash the factory
 * changes), every test below fails with a nonzero Firm::count() delta;
 * against the fixed code, every test passes with a zero delta.
 */
class EagerFactorySideEffectRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_export_batch_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        AccountingExportBatch::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_accounting_export_line_factory_for_expense_does_not_leak_wasted_firms_or_batches(): void
    {
        $firm = Firm::factory()->create();
        $batch = AccountingExportBatch::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $batchCountBefore = AccountingExportBatch::count();

        AccountingExportLine::factory()->forExpense($batch, $expense)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($batchCountBefore, AccountingExportBatch::count());
    }

    public function test_ai_approval_event_factory_for_request_does_not_leak_a_wasted_request(): void
    {
        $firm = Firm::factory()->create();
        $request = AiApprovalRequest::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $requestCountBefore = AiApprovalRequest::count();

        AiApprovalEvent::factory()->forRequest($request)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($requestCountBefore, AiApprovalRequest::count());
    }

    public function test_ai_approval_request_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        AiApprovalRequest::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_ai_tool_action_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        AiToolAction::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_communication_consent_event_factory_for_consent_does_not_leak_a_wasted_consent(): void
    {
        $firm = Firm::factory()->create();
        $consent = CommunicationConsent::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $consentCountBefore = CommunicationConsent::count();

        CommunicationConsentEvent::factory()->forConsent($consent)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($consentCountBefore, CommunicationConsent::count());
    }

    public function test_conflict_check_run_factory_for_matter_does_not_leak_a_wasted_matter(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $matterCountBefore = Matter::count();

        ConflictCheckRun::factory()->forMatter($matter)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($matterCountBefore, Matter::count());
    }

    public function test_conflict_check_run_factory_for_firm_does_not_leak_a_wasted_matter(): void
    {
        $firm = Firm::factory()->create();

        $matterCountBefore = Matter::count();
        $countBefore = Firm::count();

        ConflictCheckRun::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
        // forFirm() legitimately creates ONE new matter for the run (by design) —
        // proving the ORIGINAL definition()-level eager matter is not ALSO created.
        $this->assertSame($matterCountBefore + 1, Matter::count());
    }

    public function test_consultation_factory_for_firm_does_not_leak_a_wasted_lead(): void
    {
        $firm = Firm::factory()->create();

        $leadCountBefore = FirmLead::count();
        $countBefore = Firm::count();

        Consultation::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
        // forFirm() legitimately creates ONE new lead for the given firm —
        // proving the ORIGINAL definition()-level eager lead is not ALSO created.
        $this->assertSame($leadCountBefore + 1, FirmLead::count());
    }

    public function test_consultation_factory_for_lead_does_not_leak_a_wasted_lead(): void
    {
        $firm = Firm::factory()->create();
        $lead = FirmLead::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $leadCountBefore = FirmLead::count();

        Consultation::factory()->forLead($lead)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($leadCountBefore, FirmLead::count());
    }

    public function test_deletion_request_factory_direct_attribute_override_does_not_leak_a_wasted_matter(): void
    {
        // Mirrors the real call site:
        // tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php::createRequestForFirm()
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $matterCountBefore = Matter::count();

        DeletionRequest::factory()->create([
            'firm_id' => $firm->id,
            'subject_type' => Matter::class,
            'subject_id' => $matter->id,
        ]);

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($matterCountBefore, Matter::count());
    }

    public function test_document_chase_event_factory_for_item_does_not_leak_a_wasted_chain(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);
        $item = DocumentRequestItem::factory()->create(['document_request_id' => $request->id]);

        $countBefore = Firm::count();
        $clientCountBefore = Client::count();
        $requestCountBefore = DocumentRequest::count();
        $itemCountBefore = DocumentRequestItem::count();

        DocumentChaseEvent::factory()->forItem($item, $firm)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($clientCountBefore, Client::count());
        $this->assertSame($requestCountBefore, DocumentRequest::count());
        $this->assertSame($itemCountBefore, DocumentRequestItem::count());
    }

    public function test_document_request_factory_for_client_does_not_leak_a_wasted_client(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $clientCountBefore = Client::count();

        DocumentRequest::factory()->forClient($client)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($clientCountBefore, Client::count());
    }

    public function test_expense_approval_factory_for_expense_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();

        ExpenseApproval::factory()->forExpense($expense)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_expense_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        Expense::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_expense_receipt_factory_for_expense_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();

        ExpenseReceipt::factory()->forExpense($expense)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_firm_ai_provider_key_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        FirmAiProviderKey::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_firm_entitlement_event_factory_for_entitlement_does_not_leak_a_wasted_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $entitlement = FirmEntitlement::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $entitlementCountBefore = FirmEntitlement::count();

        FirmEntitlementEvent::factory()->forEntitlement($entitlement)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($entitlementCountBefore, FirmEntitlement::count());
    }

    public function test_form_review_event_factory_for_draft_does_not_leak_a_wasted_draft(): void
    {
        $firm = Firm::factory()->create();
        $draft = FormDraft::factory()->create(['firm_id' => $firm->id]);

        $countBefore = Firm::count();
        $draftCountBefore = FormDraft::count();

        FormReviewEvent::factory()->forDraft($draft)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($draftCountBefore, FormDraft::count());
    }

    public function test_generated_document_event_factory_for_document_does_not_leak_a_wasted_document(): void
    {
        $firm = Firm::factory()->create();
        $document = GeneratedDocument::factory()->create(['firm_id' => $firm->id]);

        $countBefore = Firm::count();
        $documentCountBefore = GeneratedDocument::count();

        GeneratedDocumentEvent::factory()->forDocument($document)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($documentCountBefore, GeneratedDocument::count());
    }

    public function test_installed_template_pack_factory_for_version_does_not_leak_a_wasted_version(): void
    {
        $version = TemplatePackVersion::factory()->create();

        $versionCountBefore = TemplatePackVersion::count();

        InstalledTemplatePack::factory()->forVersion($version)->create();

        $this->assertSame($versionCountBefore, TemplatePackVersion::count());
    }

    public function test_intake_submission_factory_for_client_does_not_leak_a_wasted_client(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $clientCountBefore = Client::count();

        IntakeSubmission::factory()->forClient($client)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($clientCountBefore, Client::count());
    }

    public function test_integration_conflict_factory_for_firm_integration_does_not_leak_wasted_records(): void
    {
        $connection = FirmIntegration::factory()->create();

        $countBefore = Firm::count();
        $connectionCountBefore = FirmIntegration::count();

        IntegrationConflict::factory()->forFirmIntegration($connection)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($connectionCountBefore, FirmIntegration::count());
    }

    public function test_integration_credential_factory_for_firm_integration_does_not_leak_wasted_records(): void
    {
        $connection = FirmIntegration::factory()->create();

        $countBefore = Firm::count();
        $connectionCountBefore = FirmIntegration::count();
        $keyCountBefore = TenantEncryptionKey::count();

        $credential = IntegrationCredential::factory()->forFirmIntegration($connection)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($connectionCountBefore, FirmIntegration::count());
        // forFirmIntegration() legitimately provisions exactly ONE encryption
        // key for the connection's real firm (never a wasted one).
        $this->assertSame($keyCountBefore + 1, TenantEncryptionKey::count());

        $key = TenantEncryptionKey::withoutGlobalScopes()->find($credential->encryption_key_id);
        $this->assertNotNull($key, 'encryption_key_id must reference a real, persisted key.');
        $this->assertSame(
            $connection->firm_id,
            $key->firm_id,
            'The credential\'s encryption_key_id must belong to the SAME firm as firm_id — not a wasted, discarded firm.'
        );
    }

    /**
     * Proves the downstream uniqueness collision this fix's own
     * idempotent-provisioning change (mirroring
     * IntegrationOAuthStateFactory::encryptFixtureVerifier()'s prior
     * fix) guards against: a real test pattern (PullSyncJobTest) calls
     * forFirmIntegration($connection) TWICE for the SAME connection/firm
     * to create two credentials. Before making encryptFixtureSecret()
     * idempotent, the second call would have violated
     * tenant_encryption_keys_firm_id_key_version_unique once
     * encryption_key_id started being computed against the row's real
     * (now firm-consistent, no longer wasted) firm.
     */
    public function test_integration_credential_factory_for_firm_integration_is_idempotent_across_repeated_calls(): void
    {
        $connection = FirmIntegration::factory()->create();

        $keyCountBefore = TenantEncryptionKey::count();

        $first = IntegrationCredential::factory()->forFirmIntegration($connection)
            ->ofType(CredentialType::OauthAccessToken)
            ->create();
        $second = IntegrationCredential::factory()->forFirmIntegration($connection)
            ->ofType(CredentialType::OauthRefreshToken)
            ->create();

        // Exactly one key ever gets provisioned for the shared firm, reused by both credentials.
        $this->assertSame($keyCountBefore + 1, TenantEncryptionKey::count());
        $this->assertSame($first->encryption_key_id, $second->encryption_key_id);
    }

    public function test_integration_inbound_webhook_event_factory_for_firm_integration_does_not_leak_wasted_records(): void
    {
        $connection = FirmIntegration::factory()->create();

        $countBefore = Firm::count();
        $connectionCountBefore = FirmIntegration::count();

        IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($connectionCountBefore, FirmIntegration::count());
    }

    public function test_integration_inbound_webhook_event_factory_for_receipt_does_not_leak_a_wasted_receipt(): void
    {
        $receipt = IntegrationWebhookReceipt::factory()->create();

        $receiptCountBefore = IntegrationWebhookReceipt::count();

        IntegrationInboundWebhookEvent::factory()->forReceipt($receipt)->create();

        $this->assertSame($receiptCountBefore, IntegrationWebhookReceipt::count());
    }

    public function test_integration_sync_cursor_factory_for_firm_integration_does_not_leak_wasted_records(): void
    {
        $connection = FirmIntegration::factory()->create();

        $countBefore = Firm::count();
        $connectionCountBefore = FirmIntegration::count();

        IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($connectionCountBefore, FirmIntegration::count());
    }

    public function test_integration_usage_record_factory_for_firm_integration_does_not_leak_wasted_records(): void
    {
        $connection = FirmIntegration::factory()->create();

        $countBefore = Firm::count();
        $connectionCountBefore = FirmIntegration::count();

        IntegrationUsageRecord::factory()->forFirmIntegration($connection)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($connectionCountBefore, FirmIntegration::count());
    }

    public function test_integration_webhook_routing_index_factory_for_firm_integration_does_not_leak_wasted_records(): void
    {
        $connection = FirmIntegration::factory()->create();

        $countBefore = Firm::count();
        $connectionCountBefore = FirmIntegration::count();

        IntegrationWebhookRoutingIndex::factory()->forFirmIntegration($connection)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($connectionCountBefore, FirmIntegration::count());
    }

    public function test_invoice_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        Invoice::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_license_file_factory_for_firm_does_not_leak_wasted_records(): void
    {
        $firm = Firm::factory()->create();
        $firmLicense = FirmLicense::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $licenseCountBefore = FirmLicense::count();

        LicenseFile::factory()->forFirm($firm, $firmLicense)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($licenseCountBefore, FirmLicense::count());
    }

    public function test_matter_expense_factory_for_expense_and_matter_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();

        MatterExpense::factory()->forExpenseAndMatter($expense, $matter)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_matter_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        Matter::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_matter_readiness_score_factory_for_matter_does_not_leak_a_wasted_matter(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $matterCountBefore = Matter::count();

        MatterReadinessScore::factory()->forMatter($matter)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($matterCountBefore, Matter::count());
    }

    public function test_payment_classification_event_factory_for_payment_does_not_leak_a_wasted_payment(): void
    {
        $firm = Firm::factory()->create();
        $payment = Payment::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $paymentCountBefore = Payment::count();

        PaymentClassificationEvent::factory()->forPayment($payment)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($paymentCountBefore, Payment::count());
    }

    public function test_payment_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        Payment::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_payment_plan_event_factory_for_plan_does_not_leak_a_wasted_plan(): void
    {
        $firm = Firm::factory()->create();
        $plan = PaymentPlan::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $planCountBefore = PaymentPlan::count();

        PaymentPlanEvent::factory()->forPlan($plan)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($planCountBefore, PaymentPlan::count());
    }

    public function test_payment_plan_factory_for_firm_does_not_leak_a_wasted_firm(): void
    {
        $firm = Firm::factory()->create();

        $countBefore = Firm::count();

        PaymentPlan::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
    }

    public function test_readiness_score_event_factory_for_matter_does_not_leak_a_wasted_matter(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $matterCountBefore = Matter::count();

        ReadinessScoreEvent::factory()->forMatter($matter)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($matterCountBefore, Matter::count());
    }

    public function test_signature_certificate_factory_for_request_does_not_leak_wasted_records(): void
    {
        $firm = Firm::factory()->create();
        $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);
        $hash = DocumentHash::factory()->create(['firm_id' => $firm->id]);

        $countBefore = Firm::count();
        $requestCountBefore = SignatureRequest::count();
        $hashCountBefore = DocumentHash::count();

        SignatureCertificate::factory()->forRequest($request, $hash)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($requestCountBefore, SignatureRequest::count());
        $this->assertSame($hashCountBefore, DocumentHash::count());
    }

    public function test_signature_event_factory_for_request_does_not_leak_wasted_records(): void
    {
        $firm = Firm::factory()->create();
        $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);

        $countBefore = Firm::count();
        $requestCountBefore = SignatureRequest::count();

        SignatureEvent::factory()->forRequest($request)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($requestCountBefore, SignatureRequest::count());
    }

    public function test_signature_request_recipient_factory_for_request_does_not_leak_a_wasted_request(): void
    {
        $firm = Firm::factory()->create();
        $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);

        $countBefore = Firm::count();
        $requestCountBefore = SignatureRequest::count();

        SignatureRequestRecipient::factory()->forRequest($request)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($requestCountBefore, SignatureRequest::count());
    }

    public function test_support_access_session_factory_direct_attribute_override_does_not_leak_a_wasted_request(): void
    {
        // Mirrors the real call site:
        // tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php::createSessionForFirm()
        $firm = Firm::factory()->create();
        $request = SupportAccessRequest::factory()->create(['firm_id' => $firm->id]);

        $countBefore = Firm::count();
        $requestCountBefore = SupportAccessRequest::count();

        SupportAccessSession::factory()->create([
            'firm_id' => $firm->id,
            'support_access_request_id' => $request->id,
        ]);

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($requestCountBefore, SupportAccessRequest::count());
    }

    public function test_template_upgrade_log_factory_for_firm_does_not_leak_a_wasted_pack(): void
    {
        $firm = Firm::factory()->create();

        $packCountBefore = InstalledTemplatePack::count();
        $countBefore = Firm::count();

        TemplateUpgradeLog::factory()->forFirm($firm)->create();

        $this->assertSame($countBefore, Firm::count());
        // forFirm() legitimately creates ONE new pack for the given firm —
        // proving the ORIGINAL definition()-level eager pack is not ALSO created.
        $this->assertSame($packCountBefore + 1, InstalledTemplatePack::count());
    }

    public function test_template_upgrade_preview_factory_for_installed_pack_does_not_leak_a_wasted_pack(): void
    {
        $firm = Firm::factory()->create();
        $pack = InstalledTemplatePack::factory()->forFirm($firm)->create();

        $countBefore = Firm::count();
        $packCountBefore = InstalledTemplatePack::count();

        TemplateUpgradePreview::factory()->forInstalledPack($pack)->create();

        $this->assertSame($countBefore, Firm::count());
        $this->assertSame($packCountBefore, InstalledTemplatePack::count());
    }
}
