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
    }

    public function test_scope_defaults_to_firm_without_organization_opt_in(): void
    {
        $matter = Matter::factory()->create();

        $summary = $this->service->run($matter, ['irrelevant']);

        $run = \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId);
        $this->assertSame(ConflictCheckScope::Firm, $run->scope);
    }

    public function test_scope_is_organization_only_with_explicit_opt_in(): void
    {
        $organization = Organization::factory()->create(['conflict_scope' => ConflictScope::OrganizationWide]);
        $firm = Firm::factory()->forOrganization($organization)->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        $summary = $this->service->run($matter, ['irrelevant']);

        $run = \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId);
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

    public function test_resolve_result_only_accepts_confirmed_conflict_or_dismissed(): void
    {
        $matter = Matter::factory()->create();
        Party::factory()->forFirm($matter->firm)->create(['name' => 'Match Me']);
        $reviewer = User::factory()->create();

        $summary = $this->service->run($matter, ['Match Me']);
        $result = \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId)->results()->first();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveResult($result, ConflictCheckResultStatus::Clear, $reviewer);
    }

    public function test_resolve_result_to_dismissed_clears_the_summary(): void
    {
        $matter = Matter::factory()->create();
        Party::factory()->forFirm($matter->firm)->create(['name' => 'Match Me']);
        $reviewer = User::factory()->create();

        $summary = $this->service->run($matter, ['Match Me']);
        $run = \App\Models\ConflictCheckRun::find($summary->conflictCheckRunId);
        $result = $run->results()->first();

        $this->service->resolveResult($result, ConflictCheckResultStatus::Dismissed, $reviewer, 'not the same person');

        $newSummary = $this->service->summarize($run->fresh('results'));
        $this->assertTrue($newSummary->isClearToProceed());
    }
}
