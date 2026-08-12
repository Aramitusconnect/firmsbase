<?php

namespace App\Services;

use App\Enums\ConflictCheckResultStatus;
use App\Enums\ConflictCheckRunStatus;
use App\Enums\ConflictCheckScope;
use App\Enums\ConflictScope;
use App\Models\Client;
use App\Models\ConflictCheckResult;
use App\Models\ConflictCheckRun;
use App\Models\Contact;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\ValueObjects\ConflictCheckSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ConflictCheckService — runs a conflict check across clients,
 * contacts, parties, matter parties (via their linked party), and
 * free-text opposing-party names, per the project rule. A run only
 * ever produces `possible_match` results — it makes no legal judgment.
 * Turning a result into `confirmed_conflict` or `dismissed` is a human
 * decision made through resolveResult(), never automatic (edge-case
 * catalog: "Conflict false positive... route to review, do not
 * silently block or ignore").
 *
 * Scope resolution: firm-scoped by default. Organization-wide scope
 * requires the firm's Organization::conflict_scope to already be
 * explicitly set to OrganizationWide (Phase 1's ConflictScope enum) —
 * a conflict_check_run never widens to organization scope just because
 * an organization exists. When organization-wide, the search reaches
 * only sibling firms under that SAME organization — never a blanket
 * platform-wide search across unrelated firms.
 */
class ConflictCheckService
{
    public function __construct(private TimelineEventRecorder $timeline) {}

    /**
     * @param  array<int, string>  $searchTerms  names/emails/phones to match
     * @param  array<int, string>  $freeTextNames  opposing-party names with
     *                                             no full party record yet (project rule: must still be captured
     *                                             and included in the gate)
     */
    public function run(
        Matter $matter,
        array $searchTerms,
        array $freeTextNames = [],
        ?User $actor = null,
    ): ConflictCheckSummary {
        $firm = $matter->firm;
        $scope = $this->resolveScope($firm);
        $firmIds = $scope === ConflictCheckScope::Organization
            ? $this->siblingFirmIds($firm)
            : [$firm->id];

        $tenantContext = new TenantContextService;

        return DB::transaction(function () use ($matter, $firm, $scope, $firmIds, $searchTerms, $freeTextNames, $actor, $tenantContext) {
            // conflict_check_runs has permanent FORCE ROW LEVEL SECURITY
            // (Section 39A-3I) — create() and the later update()/fresh()
            // below each need their own narrow tenant-context wrap.
            // Deliberately NOT wrapping this entire method body: searchClients()/
            // searchMatterParties() below already self-wrap per firm id
            // (needed for organization-wide scope spanning multiple
            // firms) — an outer wrap here would have its context cleared
            // early by their own inner finally blocks, breaking the
            // update() call further down (the exact nested-context bug
            // class first found in Section 39A-3H).
            $run = $tenantContext->runWithFirmContext($firm, fn () => ConflictCheckRun::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'requested_by' => $actor?->id,
                'status' => ConflictCheckRunStatus::Running,
                'scope' => $scope,
                'searched_terms_json' => $searchTerms,
            ]));

            $matches = $this->searchClients($firmIds, $searchTerms)
                ->concat($this->searchContacts($firmIds, $searchTerms))
                ->concat($this->searchParties($firmIds, $searchTerms))
                ->concat($this->searchMatterParties($firmIds, $searchTerms, $matter->id));

            foreach ($matches as $match) {
                ConflictCheckResult::create([
                    'conflict_check_run_id' => $run->id,
                    'matched_type' => $match['type'],
                    'matched_id' => $match['id'],
                    'matched_value' => $match['value'],
                    'status' => ConflictCheckResultStatus::PossibleMatch,
                ]);
            }

            foreach ($freeTextNames as $name) {
                ConflictCheckResult::create([
                    'conflict_check_run_id' => $run->id,
                    'matched_type' => 'free_text',
                    'matched_id' => null,
                    'matched_value' => $name,
                    'status' => ConflictCheckResultStatus::PossibleMatch,
                ]);
            }

            $resultCount = $run->results()->count();

            $run = $tenantContext->runWithFirmContext($firm, function () use ($run, $resultCount) {
                $run->update([
                    'status' => ConflictCheckRunStatus::Completed,
                    'result_count' => $resultCount,
                    'completed_at' => now(),
                ]);

                return $run->fresh('results');
            });

            $tenantContext->runWithFirmContext($firm, fn () => $this->timeline->record($firm, 'conflict_check_completed', $matter, $actor, [
                'conflict_check_run_id' => $run->id,
                'result_count' => $resultCount,
            ]));

            return $this->summarize($run);
        });
    }

    /**
     * The only path allowed to set a terminal result status. Throws if
     * given anything other than ConfirmedConflict or Dismissed —
     * PossibleMatch/Clear are not valid review outcomes.
     */
    public function resolveResult(
        ConflictCheckResult $result,
        ConflictCheckResultStatus $resolution,
        User $reviewer,
        ?string $notes = null,
    ): ConflictCheckResult {
        if (! in_array($resolution, [ConflictCheckResultStatus::ConfirmedConflict, ConflictCheckResultStatus::Dismissed], true)) {
            throw new \InvalidArgumentException(
                'resolveResult() may only set ConfirmedConflict or Dismissed.'
            );
        }

        $result->update([
            'status' => $resolution,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return $result->fresh();
    }

    public function summarize(ConflictCheckRun $run): ConflictCheckSummary
    {
        $results = $run->relationLoaded('results') ? $run->results : $run->results()->get();

        return new ConflictCheckSummary(
            conflictCheckRunId: $run->id,
            runStatus: $run->status,
            resultCount: $run->result_count,
            hasConfirmedConflicts: $results->contains(fn (ConflictCheckResult $r) => $r->status === ConflictCheckResultStatus::ConfirmedConflict),
            hasPossibleMatches: $results->contains(fn (ConflictCheckResult $r) => $r->status === ConflictCheckResultStatus::PossibleMatch),
        );
    }

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 8 — a
     * read-only, non-persisting search for MyAttorney intake-stage
     * conflict signals, used by MarketplaceIntakeConflictCheckService.
     * At intake time there is no Matter yet to attach a real
     * ConflictCheckRun to (conflict_check_runs.matter_id is NOT NULL,
     * and conversion to a real Matter is a later checkpoint) — this
     * reuses the exact same term-matching search run() uses against
     * Clients/Contacts/Parties/MatterParties, but returns raw matches
     * instead of persisting a ConflictCheckRun/ConflictCheckResult row.
     * The authoritative, persisted conflict check still only ever
     * happens against a real Matter, later, via run() — this method
     * exists to decide whether a Firm needs to review before accepting
     * the intake, not to make that decision for them.
     *
     * @param  array<int, string>  $names
     * @return Collection<int, array{type: string, id: int, value: string}>
     */
    public function searchForPossibleMatches(Firm $firm, array $names): Collection
    {
        if ($names === []) {
            return collect();
        }

        $scope = $this->resolveScope($firm);
        $firmIds = $scope === ConflictCheckScope::Organization
            ? $this->siblingFirmIds($firm)
            : [$firm->id];

        return $this->searchClients($firmIds, $names)
            ->concat($this->searchContacts($firmIds, $names))
            ->concat($this->searchParties($firmIds, $names))
            ->concat($this->searchMatterParties($firmIds, $names));
    }

    private function resolveScope(Firm $firm): ConflictCheckScope
    {
        $organization = $firm->organization;

        if ($organization && $organization->conflict_scope === ConflictScope::OrganizationWide) {
            return ConflictCheckScope::Organization;
        }

        return ConflictCheckScope::Firm;
    }

    /**
     * @return array<int, int>
     */
    private function siblingFirmIds(Firm $firm): array
    {
        if (is_null($firm->organization_id)) {
            return [$firm->id];
        }

        return Firm::query()->where('organization_id', $firm->organization_id)->pluck('id')->all();
    }

    /**
     * clients has permanent FORCE ROW LEVEL SECURITY (Section 39A-3A),
     * whose policy matches against a single app.current_firm_id value
     * — it cannot satisfy a single whereIn('firm_id', $firmIds) query
     * spanning multiple firms. Org-wide conflict search legitimately
     * needs to reach every sibling firm, so this iterates $firmIds
     * explicitly, running each firm's search under its own tenant
     * context and merging the results — the same "iterate firms
     * explicitly rather than bypass RLS" pattern used for queue/
     * console maintenance in Section 39A-2, applied here to a search
     * across firms instead of a batch job across firms.
     *
     * @param  array<int, int>  $firmIds
     * @param  array<int, string>  $terms
     */
    private function searchClients(array $firmIds, array $terms): Collection
    {
        $service = new TenantContextService;

        return collect($firmIds)->flatMap(fn (int $firmId) => $service->runWithFirmContext(
            $firmId,
            fn () => Client::withoutTenantScope()
                ->where('firm_id', $firmId)
                ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['display_name', 'legal_name', 'email', 'phone']))
                ->get()
                ->map(fn (Client $c) => ['type' => 'client', 'id' => $c->id, 'value' => $c->display_name])
        ));
    }

    /**
     * contacts has permanent FORCE ROW LEVEL SECURITY (Section 39A-3L
     * Phase B5) — see searchClients()'s docblock above for why this
     * iterates $firmIds explicitly under per-firm tenant context rather
     * than a single whereIn('firm_id', $firmIds) query.
     */
    private function searchContacts(array $firmIds, array $terms): Collection
    {
        $service = new TenantContextService;

        return collect($firmIds)->flatMap(fn (int $firmId) => $service->runWithFirmContext(
            $firmId,
            fn () => Contact::withoutTenantScope()
                ->where('firm_id', $firmId)
                ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['name', 'company', 'email', 'phone']))
                ->get()
                ->map(fn (Contact $c) => ['type' => 'contact', 'id' => $c->id, 'value' => $c->name])
        ));
    }

    /**
     * parties has permanent FORCE ROW LEVEL SECURITY (Section 39A-3L
     * Phase B5) — same per-firm iteration as searchContacts() above.
     */
    private function searchParties(array $firmIds, array $terms): Collection
    {
        $service = new TenantContextService;

        return collect($firmIds)->flatMap(fn (int $firmId) => $service->runWithFirmContext(
            $firmId,
            fn () => Party::withoutTenantScope()
                ->where('firm_id', $firmId)
                ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['name', 'company', 'email', 'phone']))
                ->get()
                ->map(fn (Party $p) => ['type' => 'party', 'id' => $p->id, 'value' => $p->name])
        ));
    }

    /**
     * Flags a matched party's presence in OTHER matters within scope —
     * this is what actually detects "the same person is opposing
     * counsel here and our own client's party there."
     *
     * The Party lookup below fetches full models (not just ids) and is
     * wrapped per-firm exactly like the Matter half above it, because
     * parties has permanent FORCE ROW LEVEL SECURITY (Section 39A-3L
     * Phase B5). The final composed MatterParty query deliberately does
     * NOT ->with('party'): by the time it runs, every runWithFirmContext()
     * call above has already returned and cleared both the Postgres
     * session setting and the PHP-memory context in its own finally
     * block, so an eager load here would run with no tenant context at
     * all and the RLS policy would deny every row. matter_parties itself
     * has no firm_id column and is correctly excluded from this
     * mission's FORCE list, so its own whereIn(...) filtering below is
     * unaffected — only the eager-loaded parties relation was at risk.
     * Instead, party names are looked up from the already-fetched,
     * already-context-wrapped $parties collection via an in-PHP
     * [$partyId => $partyName] map.
     */
    private function searchMatterParties(array $firmIds, array $terms, ?int $excludeMatterId = null): Collection
    {
        $service = new TenantContextService;

        $matterIds = collect($firmIds)->flatMap(fn (int $firmId) => $service->runWithFirmContext(
            $firmId,
            fn () => Matter::withoutTenantScope()
                ->where('firm_id', $firmId)
                ->when($excludeMatterId !== null, fn ($q) => $q->where('id', '!=', $excludeMatterId))
                ->pluck('id')
        ));

        if ($matterIds->isEmpty()) {
            return collect();
        }

        $parties = collect($firmIds)->flatMap(fn (int $firmId) => $service->runWithFirmContext(
            $firmId,
            fn () => Party::withoutTenantScope()
                ->where('firm_id', $firmId)
                ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['name', 'company', 'email', 'phone']))
                ->get()
        ));

        if ($parties->isEmpty()) {
            return collect();
        }

        $partyIds = $parties->pluck('id');
        $partyNamesById = $parties->pluck('name', 'id');

        return MatterParty::query()
            ->whereIn('matter_id', $matterIds)
            ->whereIn('party_id', $partyIds)
            ->get()
            ->map(fn (MatterParty $mp) => [
                'type' => 'matter_party',
                'id' => $mp->id,
                'value' => sprintf('%s (matter #%d)', $partyNamesById[$mp->party_id], $mp->matter_id),
            ]);
    }

    private function applyTermMatching($query, array $terms, array $columns): void
    {
        if (empty($terms)) {
            // No search terms provided: match nothing, rather than
            // leaving the closure below empty (which Eloquent/Postgres
            // would otherwise treat as an unconditional match — every
            // row "matches" zero given terms). This mattered in
            // practice once matters is forced (Section 39A-3F):
            // MatterFactory now ties a matter's own client to the same
            // firm, so an empty-terms search would otherwise flag the
            // matter's own freshly-created client as a false conflict
            // match against itself.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($outer) use ($terms, $columns) {
            foreach ($terms as $term) {
                $outer->orWhere(function ($inner) use ($term, $columns) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'ilike', "%{$term}%");
                    }
                });
            }
        });
    }
}
