<?php

namespace Tests\Feature\TimeTracking;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $entry = TimeEntry::factory()->create();

        $this->assertDatabaseHas('time_entries', ['id' => $entry->id]);
        $this->assertSame(TimeEntryStatus::Draft, $entry->status);
    }

    public function test_seconds_column_only_ever_stores_whole_integers(): void
    {
        $entry = TimeEntry::factory()->seconds(1830)->create();

        $this->assertSame(1830, $entry->fresh()->seconds);
        $this->assertIsInt($entry->fresh()->seconds);
    }

    public function test_is_eligible_for_invoicing_requires_approved_and_billable(): void
    {
        $draft = TimeEntry::factory()->create();
        $approvedNonBillable = TimeEntry::factory()->approved()->nonBillable()->create();
        $approvedBillable = TimeEntry::factory()->approved()->create();

        $this->assertFalse($draft->isEligibleForInvoicing());
        $this->assertFalse($approvedNonBillable->isEligibleForInvoicing());
        $this->assertTrue($approvedBillable->isEligibleForInvoicing());
    }

    public function test_no_uuid_column_exists(): void
    {
        $entry = TimeEntry::factory()->create();

        $this->assertArrayNotHasKey('uuid', $entry->getAttributes());
    }
}
