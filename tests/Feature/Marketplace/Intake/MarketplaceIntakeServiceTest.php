<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Exceptions\MarketplaceIntakeIneligibleException;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoints 1-2 —
 * MarketplaceIntakeService domain-model behavior: creation, the basic
 * status-transition guards, event recording, and the resumable-link
 * primitives (signed URL, resume tracking, expiry). Public-route-level
 * security (signature tampering, throttling) is proven separately in
 * PublicIntakePageSecurityTest.
 */
class MarketplaceIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceIntakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketplaceIntakeService;
    }

    public function test_start_creates_an_intake_in_started_status_with_a_started_event(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = $this->runWithFirmContext($firm, fn () => DirectoryFirm::factory()->create(['firm_id' => $firm->id]));
        $practiceArea = PracticeArea::factory()->create();

        $intake = $this->service->start($firm, $directoryFirm, $practiceArea);

        $this->assertSame(MarketplaceIntakeStatus::Started, $intake->status);
        $this->assertSame($firm->id, $intake->firm_id);
        $this->assertSame($directoryFirm->id, $intake->directory_firm_id);
        $this->assertSame($practiceArea->id, $intake->practice_area_id);
        $this->assertNotEmpty($intake->uuid);

        $event = $this->runWithFirmContext($firm, fn () => $intake->events()->sole());
        $this->assertSame(MarketplaceIntakeEventType::Started, $event->event_type);
        $this->assertNull($event->actor_firm_user_id);
    }

    public function test_start_rejects_a_directory_firm_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $directoryFirm = $this->runWithFirmContext($otherFirm, fn () => DirectoryFirm::factory()->create(['firm_id' => $otherFirm->id]));

        $this->expectException(\RuntimeException::class);

        $this->service->start($firm, $directoryFirm);
    }

    public function test_resolve_by_uuid_finds_the_intake_with_no_ambient_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $resolved = $this->service->resolveByUuid($intake->uuid);

        $this->assertNotNull($resolved);
        $this->assertSame($intake->id, $resolved->id);
    }

    public function test_resolve_by_uuid_returns_null_for_an_unknown_uuid(): void
    {
        $this->assertNull($this->service->resolveByUuid((string) Str::uuid7()));
    }

    public function test_mark_submitted_transitions_from_started_and_records_an_event(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $submitted = $this->service->markSubmitted($firm, $intake);

        $this->assertSame(MarketplaceIntakeStatus::Submitted, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);

        $events = $this->runWithFirmContext($firm, fn () => $intake->events()->pluck('event_type')->all());
        $this->assertContains(MarketplaceIntakeEventType::Submitted, $events);
    }

    public function test_mark_submitted_rejects_an_already_submitted_intake(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $this->expectException(\RuntimeException::class);

        $this->service->markSubmitted($firm, $submitted);
    }

    public function test_mark_submitted_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->markSubmitted($otherFirm, $intake);
    }

    public function test_mark_under_review_transitions_from_submitted_with_an_actor(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);
        $reviewer = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        $reviewed = $this->service->markUnderReview($firm, $submitted, $reviewer);

        $this->assertSame(MarketplaceIntakeStatus::UnderReview, $reviewed->status);

        $event = $this->runWithFirmContext($firm, fn () => $intake->events()->latest('id')->first());
        $this->assertSame(MarketplaceIntakeEventType::MarkedUnderReview, $event->event_type);
        $this->assertSame($reviewer->id, $event->actor_firm_user_id);
    }

    public function test_mark_under_review_rejects_a_started_intake(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->markUnderReview($firm, $intake);
    }

    public function test_abandon_expired_marks_a_started_intake_abandoned(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $abandoned = $this->service->abandonExpired($firm, $intake);

        $this->assertSame(MarketplaceIntakeStatus::Abandoned, $abandoned->status);
        $this->assertNotNull($abandoned->abandoned_at);
    }

    public function test_abandon_expired_rejects_an_already_terminal_intake(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $abandoned = $this->service->abandonExpired($firm, $intake);

        $this->expectException(\RuntimeException::class);

        $this->service->abandonExpired($firm, $abandoned);
    }

    public function test_start_sets_a_default_thirty_day_expiry(): void
    {
        $firm = Firm::factory()->create();

        $intake = $this->service->start($firm);

        $this->assertNotNull($intake->expires_at);
        $this->assertTrue($intake->expires_at->isFuture());
        $this->assertEqualsWithDelta(30, now()->diffInDays($intake->expires_at), 1);
    }

    public function test_signed_url_contains_the_intakes_own_uuid(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $url = $this->service->signedUrl($intake);

        $this->assertStringContainsString('/intake/'.$intake->uuid, $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_record_link_resumed_updates_last_resumed_at_and_records_an_event(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $this->service->recordLinkResumed($firm, $intake, '203.0.113.10');

        $fresh = $this->runWithFirmContext($firm, fn () => $intake->fresh());
        $this->assertNotNull($fresh->last_resumed_at);

        $events = $this->runWithFirmContext($firm, fn () => $intake->events()->pluck('event_type')->all());
        $this->assertContains(MarketplaceIntakeEventType::LinkResumed, $events);
    }

    public function test_record_link_resumed_never_changes_status(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $this->service->recordLinkResumed($firm, $intake, null);

        $fresh = $this->runWithFirmContext($firm, fn () => $intake->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Started, $fresh->status);
    }

    public function test_mark_expired_transitions_a_non_terminal_intake(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $expired = $this->service->markExpired($firm, $intake);

        $this->assertSame(MarketplaceIntakeStatus::Expired, $expired->status);

        $events = $this->runWithFirmContext($firm, fn () => $intake->events()->pluck('event_type')->all());
        $this->assertContains(MarketplaceIntakeEventType::Expired, $events);
    }

    public function test_mark_expired_is_idempotent(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $expired = $this->service->markExpired($firm, $intake);

        $again = $this->service->markExpired($firm, $expired);

        $this->assertSame(MarketplaceIntakeStatus::Expired, $again->status);
    }

    public function test_mark_expired_rejects_an_already_declined_intake(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $this->service->markSubmitted($firm, $intake);
        $declined = $this->runWithFirmContext($firm, fn () => tap($intake->fresh())->update(['status' => MarketplaceIntakeStatus::Declined, 'declined_at' => now()]));

        $this->expectException(\RuntimeException::class);

        $this->service->markExpired($firm, $declined);
    }

    public function test_is_resumable_is_false_once_expires_at_has_passed(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $this->runWithFirmContext($firm, fn () => $intake->update(['expires_at' => now()->subMinute()]));

        $this->assertFalse($this->runWithFirmContext($firm, fn () => $intake->fresh())->isResumable());
    }

    public function test_is_resumable_is_true_with_no_expiry_set(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $this->runWithFirmContext($firm, fn () => $intake->update(['expires_at' => null]));

        $this->assertTrue($this->runWithFirmContext($firm, fn () => $intake->fresh())->isResumable());
    }

    public function test_start_for_directory_firm_creates_an_intake_for_an_eligible_listing(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);

        $intake = $this->service->startForDirectoryFirm($directoryFirm);

        $this->assertSame(MarketplaceIntakeStatus::Started, $intake->status);
        $this->assertSame($firm->id, $intake->firm_id);
        $this->assertSame($directoryFirm->id, $intake->directory_firm_id);
    }

    public function test_start_for_directory_firm_rejects_an_unclaimed_listing(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create(['accepting_inquiries' => true]);

        $this->expectException(MarketplaceIntakeIneligibleException::class);

        $this->service->startForDirectoryFirm($directoryFirm);
    }

    public function test_start_for_directory_firm_rejects_a_claimed_but_non_capable_listing(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->claimed()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);

        try {
            $this->service->startForDirectoryFirm($directoryFirm);
            $this->fail('Expected MarketplaceIntakeIneligibleException.');
        } catch (MarketplaceIntakeIneligibleException $e) {
            $this->assertSame('not_capable', $e->reasonCode);
        }
    }

    public function test_start_for_directory_firm_rejects_an_unpublished_listing(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->suspended()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);

        $this->expectException(MarketplaceIntakeIneligibleException::class);

        $this->service->startForDirectoryFirm($directoryFirm);
    }

    public function test_start_for_directory_firm_rejects_a_listing_not_accepting_inquiries(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => false]);

        $this->expectException(MarketplaceIntakeIneligibleException::class);

        $this->service->startForDirectoryFirm($directoryFirm);
    }

    public function test_start_for_directory_firm_binds_the_intake_to_the_directory_firms_own_canonical_firm_only(): void
    {
        // The Firm binding comes ENTIRELY from $directoryFirm->firm_id
        // — startForDirectoryFirm() takes no separate firm parameter a
        // caller could pass a mismatched value for, so there is no
        // request-payload surface that could ever redirect an intake
        // to a different Firm than the one the visitor is actually
        // looking at.
        $realFirm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $realFirm->id, 'accepting_inquiries' => true]);

        $intake = $this->service->startForDirectoryFirm($directoryFirm);

        $this->assertSame($realFirm->id, $intake->firm_id);
        $this->assertNotSame($otherFirm->id, $intake->firm_id);
    }

    // ---------------------------------------------------------------
    // Mission 3, checkpoint 10 — markAccepted() / markDeclined()
    // ---------------------------------------------------------------

    public function test_mark_accepted_transitions_from_submitted_and_sets_accepted_at(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $accepted = $this->service->markAccepted($firm, $submitted);

        $this->assertSame(MarketplaceIntakeStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);

        $events = $this->runWithFirmContext($firm, fn () => $intake->events()->pluck('event_type')->all());
        $this->assertContains(MarketplaceIntakeEventType::Accepted, $events);
    }

    public function test_mark_accepted_transitions_from_under_review(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);
        $underReview = $this->service->markUnderReview($firm, $submitted);

        $accepted = $this->service->markAccepted($firm, $underReview);

        $this->assertSame(MarketplaceIntakeStatus::Accepted, $accepted->status);
    }

    public function test_mark_accepted_rejects_a_conflict_review_required_intake(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);
        $underReview = $this->service->markUnderReview($firm, $submitted);
        $flagged = $this->service->markConflictReviewRequired($firm, $underReview, possibleMatchCount: 1);

        $this->expectException(\RuntimeException::class);

        $this->service->markAccepted($firm, $flagged);
    }

    public function test_mark_accepted_never_creates_a_firm_lead_client_or_matter(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $this->service->markAccepted($firm, $submitted);

        $leadCount = $this->runWithFirmContext($firm, fn () => FirmLead::query()->count());
        $clientCount = $this->runWithFirmContext($firm, fn () => Client::query()->count());
        $this->assertSame(0, $leadCount);
        $this->assertSame(0, $clientCount);
    }

    public function test_mark_declined_transitions_from_submitted_and_stores_the_reason(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $declined = $this->service->markDeclined($firm, $submitted, 'Outside our practice areas.');

        $this->assertSame(MarketplaceIntakeStatus::Declined, $declined->status);
        $this->assertNotNull($declined->declined_at);
        $this->assertSame('Outside our practice areas.', $declined->decline_reason);

        $events = $this->runWithFirmContext($firm, fn () => $intake->events()->pluck('event_type')->all());
        $this->assertContains(MarketplaceIntakeEventType::Declined, $events);
    }

    public function test_mark_declined_is_allowed_from_conflict_review_required(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);
        $underReview = $this->service->markUnderReview($firm, $submitted);
        $flagged = $this->service->markConflictReviewRequired($firm, $underReview, possibleMatchCount: 1);

        $declined = $this->service->markDeclined($firm, $flagged, 'Real conflict of interest found.');

        $this->assertSame(MarketplaceIntakeStatus::Declined, $declined->status);
    }

    public function test_mark_declined_requires_a_non_empty_reason(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->markDeclined($firm, $submitted, '   ');
    }

    public function test_mark_declined_rejects_an_intake_not_pending_review(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->markDeclined($firm, $intake, 'Too early.');
    }

    public function test_mark_accepted_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $this->expectException(\RuntimeException::class);

        $this->service->markAccepted($otherFirm, $submitted);
    }
}
