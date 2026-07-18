<?php

namespace Tests\Feature\Ai\Retrieval;

use App\Enums\AiRetrievalIndexStatus;
use App\Models\AiRetrievalIndex;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Services\AiRetrievalIsolationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Project rules 13/14/15/16: retrieval isolation is structural (a
 * dedicated namespace per firm, never a shared index filtered only by
 * metadata); matter-level access enforced before retrieval; cross-firm
 * data never surfaced; cross-matter retrieval requires access to every
 * matter involved.
 */
class AiRetrievalIsolationServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    /**
     * Section 39A-5 (Wave 1): ai_retrieval_indexes now has FORCE ROW
     * LEVEL SECURITY active, and provisionFor() wraps its own write in
     * runWithFirmContext() (restored to "no context" once the call
     * returns). assertDatabaseCount()'s raw, unscoped query is
     * therefore itself subject to the RLS policy and would see zero
     * rows once no context is active — so visibility is verified per
     * firm, under that firm's own context, instead of with a single
     * ambient-context table-wide count.
     */
    public function test_each_firm_gets_a_unique_dedicated_namespace(): void
    {
        $firmA = $this->makeAiEntitledFirm();
        $firmB = $this->makeAiEntitledFirm();

        $service = app(AiRetrievalIsolationService::class);
        $indexA = $service->provisionFor($firmA);
        $indexB = $service->provisionFor($firmB);

        $this->assertNotSame($indexA->namespace_identifier, $indexB->namespace_identifier);
        $this->assertSame(AiRetrievalIndexStatus::Provisioned, $indexA->status);

        $this->assertSame(1, $this->runWithFirmContext(
            $firmA,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->where('firm_id', $firmA->id)->count(),
        ));
        $this->assertSame(1, $this->runWithFirmContext(
            $firmB,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->where('firm_id', $firmB->id)->count(),
        ));
    }

    public function test_provisioning_is_idempotent_per_firm(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $service = app(AiRetrievalIsolationService::class);

        $first = $service->provisionFor($firm);
        $second = $service->provisionFor($firm);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->runWithFirmContext(
            $firm,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->where('firm_id', $firm->id)->count(),
        ));
    }

    public function test_retrieval_context_blocks_cross_firm_matter_data(): void
    {
        $firmA = $this->makeAiEntitledFirm();
        $firmB = $this->makeAiEntitledFirm();
        $ownerOfA = $this->makeFirmOwner($firmA);
        $matterInB = Matter::factory()->forFirm($firmB)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-firm');

        app(AiRetrievalIsolationService::class)->buildContext($firmA, $ownerOfA->user, [$matterInB]);
    }

    public function test_retrieval_context_blocks_unauthorized_matter_data_within_the_same_firm(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        // Deliberately no MatterAssignment for the paralegal.

        $this->expectException(\RuntimeException::class);

        app(AiRetrievalIsolationService::class)->buildContext($firm, $paralegalFirmUser->user, [$matter]);
    }

    public function test_retrieval_context_allows_access_when_authorized(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $attorneyFirmUser = $this->makeAttorney($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        $context = app(AiRetrievalIsolationService::class)->buildContext($firm, $attorneyFirmUser->user, [$matter]);

        $this->assertTrue($context->permitsMatter($matter->id));
        $this->assertSame($firm->id, $context->firmId);
    }

    public function test_cross_matter_retrieval_requires_access_to_every_matter_involved(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matterA = Matter::factory()->forFirm($firm)->create();
        $matterB = Matter::factory()->forFirm($firm)->create();

        MatterAssignment::factory()->forMatter($matterA)->forUser($paralegalFirmUser->user)->create();
        // No assignment for matterB — the whole cross-matter request must be denied.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('every matter');

        app(AiRetrievalIsolationService::class)->buildContext($firm, $paralegalFirmUser->user, [$matterA, $matterB]);
    }

    /**
     * Section 39A-5 (Wave 1): buildContext() now wraps its own body in
     * runWithFirmContext(), and internally calls TWO further sources of
     * nested runWithFirmContext() calls of its own — provisionFor()'s
     * own wrap, and (once per requested matter, via
     * MatterAccessPolicyService::canAccessAllMatters()) canAccessMatter()'s
     * own wrap. With 2+ requested matters this exercises the deepest
     * nesting this call graph produces: outer (buildContext) ->
     * provisionFor -> [restore] -> canAccessMatter(matterA) ->
     * [restore] -> canAccessMatter(matterB) -> [restore] -> [outer
     * restore]. TenantContextService::runWithFirmContext() is
     * documented-safe for this because each call restores whatever
     * context was active immediately before it, rather than
     * unconditionally clearing — proved here by asserting no tenant
     * context remains active once buildContext() returns, even though
     * this whole test itself runs inside RefreshDatabase's own
     * transaction with no ambient firm context of its own.
     */
    public function test_build_context_with_multiple_matters_restores_context_correctly_through_nested_wraps(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $attorneyFirmUser = $this->makeAttorney($firm);
        $matterA = Matter::factory()->forFirm($firm)->create();
        $matterB = Matter::factory()->forFirm($firm)->create();

        // makeAiEntitledFirm()/makeAttorney()/MatterFactory each
        // deliberately leave the PostgreSQL session's database-only
        // tenant context set to the fixture firm afterward (their own
        // established convention). Clear it explicitly so the
        // "no leftover context" assertion below proves buildContext()
        // itself leaves no context behind, rather than merely restoring
        // that pre-existing fixture leftover.
        (new TenantContextService)->clearDatabaseTenantContext();

        $context = app(AiRetrievalIsolationService::class)->buildContext(
            $firm,
            $attorneyFirmUser->user,
            [$matterA, $matterB],
        );

        $this->assertTrue($context->permitsMatter($matterA->id));
        $this->assertTrue($context->permitsMatter($matterB->id));
        $this->assertSame($firm->id, $context->firmId);

        // No leftover context after the whole nested call graph unwinds.
        $this->assertNoDatabaseTenantContext();

        // And the provisioned index row is still correctly readable
        // under its own firm's context afterward — proving the nested
        // wraps did not corrupt or leak into some other firm's context
        // along the way.
        $index = $this->runWithFirmContext(
            $firm,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->where('firm_id', $firm->id)->first(),
        );

        $this->assertNotNull($index);
        $this->assertSame($context->namespaceIdentifier, $index->namespace_identifier);
        $this->assertNoDatabaseTenantContext();
    }
}
