<?php

namespace App\Services;

use App\Enums\AiRetrievalIndexStatus;
use App\Models\AiRetrievalIndex;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use App\ValueObjects\AiRetrievalContext;
use Illuminate\Support\Str;

/**
 * AiRetrievalIsolationService — provisions/records the structurally
 * isolated namespace for a firm (project rules 13/14: dedicated
 * namespace/partition per firm, never a shared index filtered only by
 * metadata) and builds the AiRetrievalContext every retrieval call
 * must go through.
 *
 * Phase 15 has no real vector/search backend — provision() only
 * records the CONTRACT: one unique namespace_identifier per firm,
 * never reused across firms. buildContext() is where the real
 * enforcement work happens today: it resolves, via
 * MatterAccessPolicyService, the exact set of matters this user may
 * touch, and throws rather than silently narrowing if the caller asked
 * for cross-matter context the user is not fully authorized for
 * (project rule 16).
 */
class AiRetrievalIsolationService
{
    public function __construct(private readonly MatterAccessPolicyService $matterAccessPolicy)
    {
    }

    public function provisionFor(Firm $firm): AiRetrievalIndex
    {
        return AiRetrievalIndex::query()->firstOrCreate(
            ['firm_id' => $firm->id],
            [
                'namespace_identifier' => 'firm-ns-'.(string) Str::uuid7(),
                'status' => AiRetrievalIndexStatus::Provisioned,
            ]
        );
    }

    /**
     * @param  array<Matter>  $requestedMatters
     */
    public function buildContext(Firm $firm, User $user, array $requestedMatters): AiRetrievalContext
    {
        $index = $this->provisionFor($firm);

        foreach ($requestedMatters as $matter) {
            if ($matter->firm_id !== $firm->id) {
                throw new \RuntimeException(
                    'Cross-firm AI retrieval is never authorized: matter belongs to a different firm.'
                );
            }
        }

        if (! $this->matterAccessPolicy->canAccessAllMatters($user, $requestedMatters)) {
            throw new \RuntimeException(
                'AI retrieval denied: the user does not have access to every matter requested. '.
                'Cross-matter context within a firm is unauthorized unless the user has access to all matters involved.'
            );
        }

        return new AiRetrievalContext(
            firmId: $firm->id,
            authorizedMatterIds: array_map(fn (Matter $m) => $m->id, $requestedMatters),
            namespaceIdentifier: $index->namespace_identifier,
        );
    }
}
