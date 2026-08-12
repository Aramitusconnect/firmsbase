<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 1 —
 * MarketplaceIntakeService domain-model behavior: creation, the basic
 * status-transition guards, and event recording. The full public
 * resumable-link/session layer is checkpoint 2's own scope.
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
}
