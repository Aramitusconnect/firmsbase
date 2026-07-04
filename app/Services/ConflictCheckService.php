<?php

namespace App\Services;

use App\Enums\ConflictCheckResultStatus;
use App\Enums\ConflictCheckRunStatus;
use App\Enums\ConflictCheckScope;
use App\Enums\ConflictScope;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ConflictCheckResult;
use App\Models\ConflictCheckRun;
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
    public function __construct(private TimelineEventRecorder $timeline)
    {
    }

    /**
     * @param  array<int, string>  $searchTerms  names/emails/phones to match
     * @param  array<int, string>  $freeTextNames  opposing-party names with
     *   no full party record yet (project rule: must still be captured
     *   and included in the gate)
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

        return DB::transaction(function () use ($matter, $firm, $scope, $firmIds, $searchTerms, $freeTextNames, $actor) {
            $run = ConflictCheckRun::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'requested_by' => $actor?->id,
                'status' => ConflictCheckRunStatus::Running,
                'scope' => $scope,
                'searched_terms_json' => $searchTerms,
            ]);

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

            $run->update([
                'status' => ConflictCheckRunStatus::Completed,
                'result_count' => $resultCount,
                'completed_at' => now(),
            ]);

            $this->timeline->record($firm, 'conflict_check_completed', $matter, $actor, [
                'conflict_check_run_id' => $run->id,
                'result_count' => $resultCount,
            ]);

            return $this->summarize($run->fresh('results'));
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
     * @param  array<int, int>  $firmIds
     * @param  array<int, string>  $terms
     */
    private function searchClients(array $firmIds, array $terms): Collection
    {
        return Client::withoutTenantScope()
            ->whereIn('firm_id', $firmIds)
            ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['display_name', 'legal_name', 'email', 'phone']))
            ->get()
            ->map(fn (Client $c) => ['type' => 'client', 'id' => $c->id, 'value' => $c->display_name]);
    }

    private function searchContacts(array $firmIds, array $terms): Collection
    {
        return Contact::withoutTenantScope()
            ->whereIn('firm_id', $firmIds)
            ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['name', 'company', 'email', 'phone']))
            ->get()
            ->map(fn (Contact $c) => ['type' => 'contact', 'id' => $c->id, 'value' => $c->name]);
    }

    private function searchParties(array $firmIds, array $terms): Collection
    {
        return Party::withoutTenantScope()
            ->whereIn('firm_id', $firmIds)
            ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['name', 'company', 'email', 'phone']))
            ->get()
            ->map(fn (Party $p) => ['type' => 'party', 'id' => $p->id, 'value' => $p->name]);
    }

    /**
     * Flags a matched party's presence in OTHER matters within scope —
     * this is what actually detects "the same person is opposing
     * counsel here and our own client's party there."
     */
    private function searchMatterParties(array $firmIds, array $terms, int $excludeMatterId): Collection
    {
        $matterIds = Matter::withoutTenantScope()
            ->whereIn('firm_id', $firmIds)
            ->where('id', '!=', $excludeMatterId)
            ->pluck('id');

        if ($matterIds->isEmpty()) {
            return collect();
        }

        $partyIds = Party::withoutTenantScope()
            ->whereIn('firm_id', $firmIds)
            ->where(fn ($q) => $this->applyTermMatching($q, $terms, ['name', 'company', 'email', 'phone']))
            ->pluck('id');

        if ($partyIds->isEmpty()) {
            return collect();
        }

        return MatterParty::query()
            ->whereIn('matter_id', $matterIds)
            ->whereIn('party_id', $partyIds)
            ->with(['party', 'matter'])
            ->get()
            ->map(fn (MatterParty $mp) => [
                'type' => 'matter_party',
                'id' => $mp->id,
                'value' => sprintf('%s (matter #%d)', $mp->party->name, $mp->matter_id),
            ]);
    }

    private function applyTermMatching($query, array $terms, array $columns): void
    {
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
