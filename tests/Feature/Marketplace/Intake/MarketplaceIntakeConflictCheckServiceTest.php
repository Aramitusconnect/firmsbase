<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeConflictCheckService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 8 —
 * MarketplaceIntakeConflictCheckService: the pre-conversion, pre-Matter
 * conflict signal. Every test proves this stays a lighter-weight gate
 * on the intake's own status, never touches Matter-level
 * conflict_check_runs, and never leaks matched-entity details into the
 * intake's own append-only event log.
 */
class MarketplaceIntakeConflictCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MarketplaceIntakeConflictCheckService
    {
        return app(MarketplaceIntakeConflictCheckService::class);
    }

    private function intakeService(): MarketplaceIntakeService
    {
        return app(MarketplaceIntakeService::class);
    }

    /**
     * @return array{0: Firm, 1: MarketplaceIntake}
     */
    private function setUpIntakeUnderReview(?string $opposingParties = null): array
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = $this->intakeService()->startForDirectoryFirm($directoryFirm);

        $this->runWithFirmContext($firm, function () use ($intake, $opposingParties) {
            $intake->update([
                'structured_data' => $opposingParties === null
                    ? []
                    : [MarketplaceIntakeConflictCheckService::OPPOSING_PARTIES_QUESTION_CODE => $opposingParties],
            ]);
        });

        $submitted = $this->intakeService()->markSubmitted($firm, $intake);
        $underReview = $this->intakeService()->markUnderReview($firm, $submitted);

        return [$firm, $underReview];
    }

    public function test_possible_matches_is_empty_when_no_opposing_parties_were_captured(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview();

        $matches = $this->service()->possibleMatches($firm, $intake);

        $this->assertTrue($matches->isEmpty());
    }

    public function test_possible_matches_finds_an_existing_client_by_name(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('John Smith');
        $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'John Smith']));

        $matches = $this->service()->possibleMatches($firm, $intake);

        $this->assertCount(1, $matches);
        $this->assertSame('client', $matches->first()['type']);
    }

    public function test_possible_matches_finds_an_existing_party_by_name(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('Acme Corp');
        $this->runWithFirmContext($firm, fn () => Party::factory()->create(['firm_id' => $firm->id, 'name' => 'Acme Corp']));

        $matches = $this->service()->possibleMatches($firm, $intake);

        $this->assertCount(1, $matches);
        $this->assertSame('party', $matches->first()['type']);
    }

    public function test_possible_matches_parses_multiple_names_one_per_line(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview("John Smith\nAcme Corp\n\n  Jane Doe  ");
        $this->runWithFirmContext($firm, function () use ($firm) {
            Client::factory()->forFirm($firm)->create(['display_name' => 'John Smith']);
            Party::factory()->create(['firm_id' => $firm->id, 'name' => 'Acme Corp']);
        });

        $matches = $this->service()->possibleMatches($firm, $intake);

        $this->assertCount(2, $matches);
    }

    public function test_possible_matches_never_leaks_a_different_firms_client(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('John Smith');
        $otherFirm = Firm::factory()->create();
        $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create(['display_name' => 'John Smith']));

        $matches = $this->service()->possibleMatches($firm, $intake);

        $this->assertTrue($matches->isEmpty());
    }

    public function test_evaluate_transitions_to_conflict_review_required_when_a_match_is_found(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('John Smith');
        $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'John Smith']));

        $result = $this->service()->evaluate($firm, $intake);

        $this->assertSame(MarketplaceIntakeStatus::ConflictReviewRequired, $result->status);
    }

    public function test_evaluate_leaves_a_clean_intake_untouched(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('Nobody Matching');

        $result = $this->service()->evaluate($firm, $intake);

        $this->assertSame(MarketplaceIntakeStatus::UnderReview, $result->status);
    }

    public function test_evaluate_records_only_the_match_count_never_the_matched_name(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('John Smith');
        $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'John Smith']));

        $this->service()->evaluate($firm, $intake);

        $event = $this->runWithFirmContext($firm, fn () => $intake->events()->latest('id')->first());
        $this->assertSame(MarketplaceIntakeEventType::ConflictReviewRequired, $event->event_type);
        $this->assertSame(1, $event->metadata['possible_match_count']);
        $this->assertArrayNotHasKey('matched_value', $event->metadata);
        $this->assertStringNotContainsString('John Smith', json_encode($event->metadata));
    }

    public function test_evaluate_throws_when_the_intake_is_not_under_review(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = $this->intakeService()->startForDirectoryFirm($directoryFirm);

        $this->expectException(\RuntimeException::class);

        $this->service()->evaluate($firm, $intake);
    }

    public function test_clear_conflict_review_transitions_back_to_under_review(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview('John Smith');
        $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'John Smith']));
        $flagged = $this->service()->evaluate($firm, $intake);

        $cleared = $this->intakeService()->clearConflictReview($firm, $flagged);

        $this->assertSame(MarketplaceIntakeStatus::UnderReview, $cleared->status);
        $event = $this->runWithFirmContext($firm, fn () => $intake->events()->latest('id')->first());
        $this->assertSame(MarketplaceIntakeEventType::ConflictReviewCleared, $event->event_type);
    }

    public function test_clear_conflict_review_rejects_an_intake_not_in_conflict_review(): void
    {
        [$firm, $intake] = $this->setUpIntakeUnderReview();

        $this->expectException(\RuntimeException::class);

        $this->intakeService()->clearConflictReview($firm, $intake);
    }

    public function test_mark_conflict_review_required_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        [, $intake] = $this->setUpIntakeUnderReview('John Smith');
        $otherFirm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->intakeService()->markConflictReviewRequired($otherFirm, $intake);
    }
}
