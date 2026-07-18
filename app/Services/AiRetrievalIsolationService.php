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
 *
 * Section 39A-5 (Wave 1): ai_retrieval_indexes now has FORCE ROW LEVEL
 * SECURITY active (see database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php),
 * so every read/write against it requires the PostgreSQL session's
 * app.current_firm_id to already match the row's firm_id. Both
 * provisionFor() and buildContext() below independently wrap their own
 * body in TenantContextService::runWithFirmContext($firm, ...) — each
 * whole call site is wrapped, not merely the write argument, per this
 * codebase's established convention. buildContext() calls
 * provisionFor() internally, so once both are wrapped a call to
 * buildContext() runs a nested runWithFirmContext(): this is
 * documented-safe (see TenantContextService::runWithFirmContext()'s own
 * docblock) because each call restores whatever context was active
 * immediately before it, rather than unconditionally clearing —
 * provisionFor()'s inner wrap restores buildContext()'s outer context
 * on exit instead of wiping it.
 */
class AiRetrievalIsolationService
{
    public function __construct(private readonly MatterAccessPolicyService $matterAccessPolicy) {}

    public function provisionFor(Firm $firm): AiRetrievalIndex
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => AiRetrievalIndex::query()->firstOrCreate(
            ['firm_id' => $firm->id],
            [
                'namespace_identifier' => 'firm-ns-'.(string) Str::uuid7(),
                'status' => AiRetrievalIndexStatus::Provisioned,
            ]
        ));
    }

    /**
     * @param  array<Matter>  $requestedMatters
     */
    public function buildContext(Firm $firm, User $user, array $requestedMatters): AiRetrievalContext
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $user, $requestedMatters) {
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
        });
    }
}
