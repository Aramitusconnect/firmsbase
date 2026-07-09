<?php

namespace Tests\Feature\Matters;

use App\Enums\ConflictCheckResultStatus;
use App\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\Party;
use App\Models\User;
use App\Services\ConflictCheckService;
use App\Services\MatterOpeningService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The core enforcement test for the project rule "conflict checks must
 * run before opening a matter" — a matter must NOT be able to reach
 * `open` status any way other than through MatterOpeningService, and
 * MatterOpeningService must refuse to open a matter whose conflict
 * check is not clear.
 */
class MatterOpeningServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterOpeningService $service;
    private ConflictCheckService $conflictCheckService;

    protected function setUp(): void
    {
        parent::setUp();
        $timeline = new TimelineEventRecorder();
        $this->conflictCheckService = new ConflictCheckService($timeline);
        $this->service = new MatterOpeningService($this->conflictCheckService, $timeline);
    }

    public function test_request_conflict_check_moves_draft_matter_to_conflict_review(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();

        $this->service->requestConflictCheck($matter, ['Jane Doe']);

        $this->assertSame(MatterStatus::ConflictReview, $this->runWithFirmContext($matter->firm, fn () => $matter->fresh())->status);
    }

    public function test_open_matter_succeeds_when_conflict_check_is_clear(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();

        $run = $this->service->requestConflictCheck($matter, ['no_such_name_anywhere_xyz']);

        $opened = $this->service->openMatter($this->runWithFirmContext($matter->firm, fn () => $matter->fresh()), $run);

        $this->assertSame(MatterStatus::Open, $opened->status);
        $this->assertNotNull($opened->opened_at);
    }

    public function test_open_matter_throws_when_possible_matches_are_unresolved(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();
        $conflictingParty = Party::factory()->forFirm($matter->firm)->create(['name' => 'Conflicting Person']);

        $run = $this->service->requestConflictCheck($matter, ['Conflicting Person']);

        $this->expectException(\RuntimeException::class);

        $this->service->openMatter($this->runWithFirmContext($matter->firm, fn () => $matter->fresh()), $run);
    }

    public function test_open_matter_succeeds_after_possible_matches_are_dismissed(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();
        Party::factory()->forFirm($matter->firm)->create(['name' => 'Conflicting Person']);
        $reviewer = User::factory()->create();

        $run = $this->service->requestConflictCheck($matter, ['Conflicting Person']);

        foreach ($run->results as $result) {
            $this->conflictCheckService->resolveResult($result, ConflictCheckResultStatus::Dismissed, $reviewer, 'Different person, common name');
        }

        $opened = $this->service->openMatter($this->runWithFirmContext($matter->firm, fn () => $matter->fresh()), $run->fresh(), $reviewer);

        $this->assertSame(MatterStatus::Open, $opened->status);
    }

    public function test_open_matter_throws_when_a_result_is_a_confirmed_conflict(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();
        Party::factory()->forFirm($matter->firm)->create(['name' => 'Definitely Conflicted']);
        $reviewer = User::factory()->create();

        $run = $this->service->requestConflictCheck($matter, ['Definitely Conflicted']);

        foreach ($run->results as $result) {
            $this->conflictCheckService->resolveResult($result, ConflictCheckResultStatus::ConfirmedConflict, $reviewer);
        }

        $this->expectException(\RuntimeException::class);

        $this->service->openMatter($this->runWithFirmContext($matter->firm, fn () => $matter->fresh()), $run->fresh(), $reviewer);
    }

    public function test_open_matter_throws_when_matter_is_not_in_conflict_review(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();
        $run = \App\Models\ConflictCheckRun::factory()->forMatter($matter)->completed()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->openMatter($matter, $run);
    }

    public function test_open_matter_throws_when_run_belongs_to_a_different_matter(): void
    {
        $matterA = Matter::factory()->status(MatterStatus::Draft)->create();
        $matterB = Matter::factory()->status(MatterStatus::Draft)->create();

        $runForB = $this->service->requestConflictCheck($matterB, ['irrelevant']);

        $this->service->requestConflictCheck($matterA, ['irrelevant']);

        $this->expectException(\RuntimeException::class);

        $this->service->openMatter($this->runWithFirmContext($matterA->firm, fn () => $matterA->fresh()), $runForB);
    }
}
