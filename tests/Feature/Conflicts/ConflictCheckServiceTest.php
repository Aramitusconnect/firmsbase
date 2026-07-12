<?php

namespace Tests\Feature\Conflicts;

use App\Enums\ConflictCheckResultStatus;
use App\Enums\ConflictCheckRunStatus;
use App\Enums\ConflictCheckScope;
use App\Enums\ConflictScope;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\ConflictCheckService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConflictCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConflictCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConflictCheckService(new TimelineEventRecorder());
    }

    public function test_run_with_no_matches_is_clear_to_proceed(): void
    {
        $matter = Matter::factory()->create();

        $summary = $this->service->run($matter, ['no_such_name_xyz']);

        $this->assertSame(ConflictCheckRunStatus::Completed, $summary->runStatus);
        $this->assertSame(0, $summary->resultCount);
        $this->assertTrue($summary->isClearToProceed());
    }

    public function test_run_matches_a_client_by_name(): void
    {
        $matter = Matter::factory()->create();
        Client::factory()->forFirm($matter->firm)->create(['display_name' => 'John Smith']);

        $summary = $this->service->run($matter, ['John Smith']);

        $this->assertSame(1, $summary->resultCount);
        $this->assertTrue($summary->hasPossibleMatches);
        $this->assertFalse($summary->isClearToProceed());
    }

    public function test_run_matches_a_contact_by_email(): void
    {
        $matter = Matter::factory()->create();
        Contact::factory()->create(['firm_id' => $matter->firm_id, 'email' => 'conflict@example.com']);

        $summary = $this->service->run($matter, ['conflict@example.com']);

        $this->assertSame(1, $summary->resultCount);
    }

    public function test_run_matches_a_party_by_phone(): void
    {
        $matter = Matter::factory()->create();
        Party::factory()->forFirm($matter->firm)->create(['phone' => '555-0100']);

        $summary = $this->service->run($matter, ['555-0100']);

        $this->assertSame(1, $summary->resultCount);
    }

    public function test_run_captures_free_text_opposing_party_names(): void
    {
        $matter = Matter::factory()->create();

        $summary = $this->service->run($matter, [], ['Unrecorded Opposing Party']);

        $this->assertSame(1, $summary->resultCount);

        $this->assertDatabaseHas('conflict_check_results', [
            'matched_type' => 'free_text',
            'matched_value' => 'Unrecorded Opposing Party',
        ]);
    }

    public function test_run_flags_a_party_already_linked_to_another_matter(): void
    {
        $firm = Firm::factory()->create();
        $existingMatter = Matter::factory()->forFirm($firm)->create();
        $party = Party::factory()->forFirm($firm)->create(['name' => 'Repeat Party']);
        MatterParty::factory()->forMatter($existingMatter)->forParty($party)->create();

        $newMatter = Matter::factory()->forFirm($firm)->create();

        $summary = $this->service->run($newMatter, ['Repeat Party']);

        $this->assertGreaterThanOrEqual(1, $summary->resultCount);

        $this->assertDatabaseHas('conflict_check_results', [
            'matched_type' => 'matter_party',
        ]);

        // Section 39A-3L Phase B5 proof: searchMatterParties() no longer
        // eager-loads ->with('party') (that relation would return null
        // once `parties` is FORCE-enabled, since the eager load runs
        // after every per-firm runWithFirmContext() call above it has
        // already cleared its context in its own finally block — see
        // ConflictCheckService::searchMatterParties()'s docblock). It
        // now builds matched_value from an in-PHP [$partyId => $name]
        // map instead. Assert the real party name actually made it into
        // matched_value, not just that a matter_party-typed row exists
        // (a null-safe sprintf() failure or a silently empty name would
        // both still satisfy the assertDatabaseHas() above).
        $matterPartyResult = $this->runWithFirmContext(
            $firm,
            fn () => \App\Models\ConflictCheckResult::query()->where('matched_type', 'matter_party')->first()
        );

        $this->assertNotNull($matterPartyResult, 'expected a matter_party result to have been persisted');
        $this->assertSame(
            sprintf('Repeat Party (matter #%d)', $existingMatter->id),
            $matterPartyResult->matched_value,
            'matched_value must contain the real party name from the in-PHP name map, not a null/blank placeholder'
        );
    }

    public function test_scope_defaults_to_firm_without_organization_opt_in(): void
    {
        $matter = Matter::factory()->create();

        $summary = $this->service->run($matter, ['irrelevant']);

        // conflict_check_runs has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3I) — service::run() correctly clears tenant
        // context after it returns, so this post-call read must
        // re-establish it explicitly.
        $run = $this->runWithFirmContext($matter->firm, fn () => \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId));
        $this->assertSame(ConflictCheckScope::Firm, $run->scope);
    }

    public function test_scope_is_organization_only_with_explicit_opt_in(): void
    {
        $organization = Organization::factory()->create(['conflict_scope' => ConflictScope::OrganizationWide]);
        $firm = Firm::factory()->forOrganization($organization)->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        $summary = $this->service->run($matter, ['irrelevant']);

        $run = $this->runWithFirmContext($firm, fn () => \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId));
        $this->assertSame(ConflictCheckScope::Organization, $run->scope);
    }

    public function test_organization_scope_only_reaches_sibling_firms_not_the_whole_platform(): void
    {
        $organization = Organization::factory()->create(['conflict_scope' => ConflictScope::OrganizationWide]);
        $firmA = Firm::factory()->forOrganization($organization)->create();
        $firmB = Firm::factory()->forOrganization($organization)->create();
        $unrelatedFirm = Firm::factory()->create(); // no organization at all

        Client::factory()->forFirm($firmB)->create(['display_name' => 'Sibling Firm Client']);
        Client::factory()->forFirm($unrelatedFirm)->create(['display_name' => 'Unrelated Firm Client']);

        $matter = Matter::factory()->forFirm($firmA)->create();

        $summaryForSibling = $this->service->run($matter, ['Sibling Firm Client']);
        $this->assertSame(1, $summaryForSibling->resultCount, 'org-wide opt-in must reach a sibling firm');

        $matter2 = Matter::factory()->forFirm($firmA)->create();
        $summaryForUnrelated = $this->service->run($matter2, ['Unrelated Firm Client']);
        $this->assertSame(0, $summaryForUnrelated->resultCount, 'org-wide opt-in must NOT reach a firm outside the organization');
    }

    /**
     * Section 39A-3L Phase B5 proof: searchContacts()/searchParties()
     * were rewritten from a single whereIn('firm_id', $firmIds) query
     * to the per-firm-iterate-and-merge pattern (matching
     * searchClients()'s existing approach), because contacts/parties
     * will carry permanent FORCE ROW LEVEL SECURITY whose policy only
     * ever matches a single app.current_firm_id value at a time. This
     * test proves the merge genuinely spans firms — a match seeded in
     * EACH of two sibling firms under the same OrganizationWide scope
     * must both surface in a single run(), not just the first firm
     * iterated or only the run()-owning firm.
     */
    public function test_organization_scope_finds_contact_and_party_matches_in_both_sibling_firms(): void
    {
        $organization = Organization::factory()->create(['conflict_scope' => ConflictScope::OrganizationWide]);
        $firmA = Firm::factory()->forOrganization($organization)->create();
        $firmB = Firm::factory()->forOrganization($organization)->create();

        $contactA = Contact::factory()->create(['firm_id' => $firmA->id, 'name' => 'Shared Term Match']);
        $contactB = Contact::factory()->create(['firm_id' => $firmB->id, 'name' => 'Shared Term Match']);

        $matter = Matter::factory()->forFirm($firmA)->create();
        $contactSummary = $this->service->run($matter, ['Shared Term Match']);

        $this->assertSame(2, $contactSummary->resultCount, 'a contact match in each sibling firm must both be found');

        $contactRun = $this->runWithFirmContext($firmA, fn () => \App\Models\ConflictCheckRun::find($contactSummary->conflictCheckRunId)->results);
        $matchedContactIds = $contactRun->where('matched_type', 'contact')->pluck('matched_id')->all();
        $this->assertEqualsCanonicalizing([$contactA->id, $contactB->id], $matchedContactIds, 'both firms\' contact ids must be present, not just one');

        $partyA = Party::factory()->create(['firm_id' => $firmA->id, 'name' => 'Shared Term Party']);
        $partyB = Party::factory()->create(['firm_id' => $firmB->id, 'name' => 'Shared Term Party']);

        $matter2 = Matter::factory()->forFirm($firmA)->create();
        $partySummary = $this->service->run($matter2, ['Shared Term Party']);

        $this->assertSame(2, $partySummary->resultCount, 'a party match in each sibling firm must both be found');

        $partyRun = $this->runWithFirmContext($firmA, fn () => \App\Models\ConflictCheckRun::find($partySummary->conflictCheckRunId)->results);
        $matchedPartyIds = $partyRun->where('matched_type', 'party')->pluck('matched_id')->all();
        $this->assertEqualsCanonicalizing([$partyA->id, $partyB->id], $matchedPartyIds, 'both firms\' party ids must be present, not just one');
    }

    public function test_resolve_result_only_accepts_confirmed_conflict_or_dismissed(): void
    {
        $matter = Matter::factory()->create();
        Party::factory()->forFirm($matter->firm)->create(['name' => 'Match Me']);
        $reviewer = User::factory()->create();

        $summary = $this->service->run($matter, ['Match Me']);
        $result = $this->runWithFirmContext($matter->firm, fn () => \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId)->results()->first());

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveResult($result, ConflictCheckResultStatus::Clear, $reviewer);
    }

    public function test_resolve_result_to_dismissed_clears_the_summary(): void
    {
        $matter = Matter::factory()->create();
        Party::factory()->forFirm($matter->firm)->create(['name' => 'Match Me']);
        $reviewer = User::factory()->create();

        $summary = $this->service->run($matter, ['Match Me']);
        $run = $this->runWithFirmContext($matter->firm, fn () => \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId));
        $result = $run->results()->first();

        $this->service->resolveResult($result, ConflictCheckResultStatus::Dismissed, $reviewer, 'not the same person');

        $newSummary = $this->runWithFirmContext($matter->firm, fn () => $this->service->summarize($run->fresh('results')));
        $this->assertTrue($newSummary->isClearToProceed());
    }
}
