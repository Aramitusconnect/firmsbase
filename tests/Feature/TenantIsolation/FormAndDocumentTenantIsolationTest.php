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

    public function test_form_draft_model_query_is_narrowed_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        FormDraft::factory()->create(['firm_id' => $firmA->id]);
        FormDraft::factory()->create(['firm_id' => $firmB->id]);

        (new TenantContextResolver())->activateForFirm($firmA);

        $this->assertSame(1, FormDraft::query()->count());

        TenantContextResolver::clear();
    }

    public function test_generated_document_query_is_narrowed_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        GeneratedDocument::factory()->forFirm($firmA)->create();
        GeneratedDocument::factory()->forFirm($firmB)->create();

        (new TenantContextResolver())->activateForFirm($firmA);

        $this->assertSame(1, GeneratedDocument::query()->count());

        TenantContextResolver::clear();
    }

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }
}
