<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentRequestItemStatus;
use App\Models\DocumentRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentRequestItemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "Client reminders stop when approved, waived, expired, or paused
     * by staff" (PDF, Document request item row).
     *
     * @return array<string, array<int, DocumentRequestItemStatus|bool>>
     */
    public static function chaseEligibilityProvider(): array
    {
        return [
            'requested is eligible' => [DocumentRequestItemStatus::Requested, true],
            'viewed is eligible' => [DocumentRequestItemStatus::Viewed, true],
            'needs_replacement is eligible' => [DocumentRequestItemStatus::NeedsReplacement, true],
            'submitted is not eligible' => [DocumentRequestItemStatus::Submitted, false],
            'under_review is not eligible' => [DocumentRequestItemStatus::UnderReview, false],
            'approved is not eligible' => [DocumentRequestItemStatus::Approved, false],
            'rejected is not eligible' => [DocumentRequestItemStatus::Rejected, false],
            'expired is not eligible' => [DocumentRequestItemStatus::Expired, false],
            'waived is not eligible' => [DocumentRequestItemStatus::Waived, false],
        ];
    }

    #[DataProvider('chaseEligibilityProvider')]
    public function test_is_chase_eligible_status_matches_the_pdf_rule(DocumentRequestItemStatus $status, bool $expected): void
    {
        $item = DocumentRequestItem::factory()->create(['status' => $status]);

        $this->assertSame($expected, $item->isChaseEligibleStatus());
    }
}
