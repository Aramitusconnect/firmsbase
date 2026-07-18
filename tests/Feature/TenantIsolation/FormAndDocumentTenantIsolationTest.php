<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\FormDraft;
use App\Models\GeneratedDocument;
use App\Services\TenantContextResolver;
use App\Services\TenantSafeFormAndDocumentPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defense-in-depth: proves cross-firm access is blocked by an explicit
 * service-level assertion, independent of whatever BelongsToTenant
 * global scope may or may not be active for the current request. Also
 * proves FormDraft/GeneratedDocument queries are narrowed to the owning
 * firm via BelongsToTenant.
 */
class FormAndDocumentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantSafeFormAndDocumentPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TenantSafeFormAndDocumentPolicyService();
    }

    public function test_form_draft_belonging_to_a_different_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $draft = FormDraft::factory()->create(['firm_id' => $firmA->id]);

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertFormDraftBelongsToFirm($draft, $firmB);
    }

    public function test_form_draft_belonging_to_the_same_firm_passes(): void
    {
        $firm = Firm::factory()->create();
        $draft = FormDraft::factory()->create(['firm_id' => $firm->id]);

        $this->policy->assertFormDraftBelongsToFirm($draft, $firm);
        $this->addToAssertionCount(1);
    }

    public function test_generated_document_belonging_to_a_different_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $document = GeneratedDocument::factory()->forFirm($firmA)->create();

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertGeneratedDocumentBelongsToFirm($document, $firmB);
    }

    /**
     * Narrowly updated for Section 39A-6 Wave 6: form_drafts now has
     * permanent FORCE ROW LEVEL SECURITY. The previous version of this
     * test called ONLY (new TenantContextResolver())->activateForFirm($firmA)
     * — PHP-memory context only, never the PostgreSQL session's
     * app.current_firm_id. Under FORCE RLS, the context-hold create()
     * override each of these factories gained in this same batch leaves
     * the DATABASE session pointed at whichever firm was created LAST
     * (firm B here) — NOT firm A — so a bare FormDraft::query()->count()
     * would combine PHP-memory scoping (firm A, via BelongsToTenant) with
     * a DB-session RLS filter still set to firm B, yielding 0 rows
     * instead of 1. Using runWithFirmContext() instead establishes BOTH
     * layers of context together for firm A, matching every other
     * FORCE-RLS-era test in this codebase.
     */
    public function test_form_draft_model_query_is_narrowed_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        FormDraft::factory()->create(['firm_id' => $firmA->id]);
        FormDraft::factory()->create(['firm_id' => $firmB->id]);

        $count = $this->runWithFirmContext($firmA, fn () => FormDraft::query()->count());

        $this->assertSame(1, $count);
    }

    /**
     * Narrowly updated for Section 39A-6 Wave 6: generated_documents now
     * has permanent FORCE ROW LEVEL SECURITY — see this file's own
     * test_form_draft_model_query_is_narrowed_to_the_active_tenant()
     * docblock immediately above for the full diagnosis; identical fix
     * applied here.
     */
    public function test_generated_document_query_is_narrowed_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        GeneratedDocument::factory()->forFirm($firmA)->create();
        GeneratedDocument::factory()->forFirm($firmB)->create();

        $count = $this->runWithFirmContext($firmA, fn () => GeneratedDocument::query()->count());

        $this->assertSame(1, $count);
    }

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }
}
