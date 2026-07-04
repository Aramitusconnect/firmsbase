<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DocumentRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\User;
use App\Services\DocumentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentRequestService();
    }

    public function test_create_requires_at_least_one_item(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($firm, $client, []);
    }

    public function test_create_builds_the_request_and_its_items(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $request = $this->service->create($firm, $client, [
            ['label' => 'Passport copy'],
            ['label' => 'Optional cover letter', 'is_required' => false],
        ]);

        $this->assertSame(DocumentRequestStatus::Open, $request->status);
        $this->assertCount(2, $request->items);
        $this->assertTrue($request->items[0]->is_required);
        $this->assertFalse($request->items[1]->is_required);
    }

    public function test_request_status_becomes_partially_fulfilled_when_one_item_is_approved(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $reviewer = User::factory()->create();

        $request = $this->service->create($firm, $client, [
            ['label' => 'Passport copy'],
            ['label' => 'Birth certificate'],
        ]);

        $this->service->approve($request->items[0], $reviewer);

        $this->assertSame(DocumentRequestStatus::PartiallyFulfilled, $request->fresh()->status);
    }

    public function test_request_status_becomes_fulfilled_only_when_every_item_reaches_a_terminal_status(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $reviewer = User::factory()->create();

        $request = $this->service->create($firm, $client, [
            ['label' => 'Passport copy'],
            ['label' => 'Birth certificate'],
        ]);

        $this->service->approve($request->items[0], $reviewer);
        $this->service->waive($request->items[1], $reviewer, 'not applicable for this matter type');

        $this->assertSame(DocumentRequestStatus::Fulfilled, $request->fresh()->status);
    }

    public function test_needs_replacement_moves_an_item_back_to_a_chase_eligible_status(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $reviewer = User::factory()->create();

        $request = $this->service->create($firm, $client, [['label' => 'Passport copy']]);
        $item = $this->service->markSubmitted($request->items[0]);
        $item = $this->service->requestReplacement($item, $reviewer, 'photo page is unreadable');

        $this->assertSame(DocumentRequestItemStatus::NeedsReplacement, $item->status);
        $this->assertTrue($item->isChaseEligibleStatus());
    }

    public function test_submit_is_refused_from_a_terminal_status(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $reviewer = User::factory()->create();

        $request = $this->service->create($firm, $client, [['label' => 'Passport copy']]);
        $item = $this->service->approve($request->items[0], $reviewer);

        $this->expectException(\RuntimeException::class);
        $this->service->markSubmitted($item);
    }
}
