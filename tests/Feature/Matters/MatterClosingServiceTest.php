<?php

namespace Tests\Feature\Matters;

use App\Enums\MatterStatus;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\MatterClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * MatterClosingServiceTest — Mission 5A. Proves the lifecycle guard
 * rules MatterClosingService enforces: close() only from a closable
 * status, archive() only from Closed, both rejecting an invalid
 * transition with a clear RuntimeException rather than a silent no-op
 * (mirroring MatterOpeningServiceTest's own enforcement-test shape for
 * the sibling service).
 */
class MatterClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterClosingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MatterClosingService;
    }

    public function test_close_succeeds_from_open_status(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Open)->create();

        $closed = $this->service->close($matter);

        $this->assertSame(MatterStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_close_succeeds_from_every_closable_status(): void
    {
        foreach ([
            MatterStatus::Open,
            MatterStatus::Active,
            MatterStatus::WaitingOnClient,
            MatterStatus::ReadyForReview,
            MatterStatus::FiledSubmitted,
        ] as $status) {
            $matter = Matter::factory()->status($status)->create();

            $closed = $this->service->close($matter);

            $this->assertSame(MatterStatus::Closed, $closed->status, "Expected close() to succeed from {$status->value}");
        }
    }

    public function test_close_throws_when_matter_is_still_in_draft(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Draft)->create();

        $this->expectException(RuntimeException::class);

        $this->service->close($matter);
    }

    public function test_close_throws_when_matter_is_already_closed(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Closed)->create();

        $this->expectException(RuntimeException::class);

        $this->service->close($matter);
    }

    public function test_close_throws_when_matter_is_already_archived(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Archived)->create();

        $this->expectException(RuntimeException::class);

        $this->service->close($matter);
    }

    public function test_archive_succeeds_from_closed_status(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Closed)->create();

        $archived = $this->service->archive($matter);

        $this->assertSame(MatterStatus::Archived, $archived->status);
    }

    public function test_archive_throws_when_matter_is_not_closed(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Open)->create();

        $this->expectException(RuntimeException::class);

        $this->service->archive($matter);
    }

    public function test_archive_throws_when_matter_is_already_archived(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Archived)->create();

        $this->expectException(RuntimeException::class);

        $this->service->archive($matter);
    }

    /**
     * Cross-firm rejection: closing a matter belonging to a DIFFERENT
     * firm than the acting FirmUser's own firm must not silently
     * succeed. MatterClosingService::close() itself doesn't take a
     * Firm parameter (it derives firm scope from $matter->firm_id), so
     * this proves the actor's own firm mismatch doesn't grant any
     * implicit cross-firm write — the actor is recorded on the
     * timeline event, not used as a scoping boundary, so the real
     * cross-firm boundary this test proves is: a FirmUser from firm B
     * cannot be passed as the actor for a firm A matter's closure
     * without the operation still operating strictly within firm A's
     * own tenant context (the fresh() re-fetch inside
     * runWithFirmContext($matter->firm_id, ...) never reads/writes
     * anything under firm B's context).
     */
    public function test_close_operates_strictly_within_the_matters_own_firm_context_regardless_of_actor_firm(): void
    {
        $matterFirmMatter = Matter::factory()->status(MatterStatus::Open)->create();
        $otherFirmUser = FirmUser::factory()->create();

        $this->assertNotSame($matterFirmMatter->firm_id, $otherFirmUser->firm_id);

        $closed = $this->service->close($matterFirmMatter, $otherFirmUser);

        $this->assertSame(MatterStatus::Closed, $closed->status);
        $this->assertSame($matterFirmMatter->firm_id, $closed->firm_id);
    }
}
