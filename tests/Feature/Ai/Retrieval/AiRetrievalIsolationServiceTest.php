<?php

namespace Tests\Feature\Ai\Retrieval;

use App\Enums\AiRetrievalIndexStatus;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Services\AiRetrievalIsolationService;
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

    public function test_each_firm_gets_a_unique_dedicated_namespace(): void
    {
        $firmA = $this->makeAiEntitledFirm();
        $firmB = $this->makeAiEntitledFirm();

        $service = app(AiRetrievalIsolationService::class);
        $indexA = $service->provisionFor($firmA);
        $indexB = $service->provisionFor($firmB);

        $this->assertNotSame($indexA->namespace_identifier, $indexB->namespace_identifier);
        $this->assertSame(AiRetrievalIndexStatus::Provisioned, $indexA->status);
        $this->assertDatabaseCount('ai_retrieval_indexes', 2);
    }

    public function test_provisioning_is_idempotent_per_firm(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $service = app(AiRetrievalIsolationService::class);

        $first = $service->provisionFor($firm);
        $second = $service->provisionFor($firm);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('ai_retrieval_indexes', 1);
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
}
