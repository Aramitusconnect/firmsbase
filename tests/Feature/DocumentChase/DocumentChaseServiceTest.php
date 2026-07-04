<?php

namespace Tests\Feature\DocumentChase;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\DocumentChaseRuleStatus;
use App\Enums\DocumentRequestItemStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\DocumentChaseRule;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\ConsentService;
use App\Services\DocumentChaseService;
use App\Services\NotificationEligibilityService;
use App\Services\SuppressionService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentChaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentChaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentChaseService(
            new NotificationEligibilityService(new ConsentService(), new SuppressionService()),
            new TimelineEventRecorder(),
        );
    }

    private function itemFor(Firm $firm, Client $client, DocumentRequestItemStatus $status): DocumentRequestItem
    {
        $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);

        return DocumentRequestItem::factory()->create(['document_request_id' => $request->id, 'status' => $status]);
    }

    public function test_no_event_is_logged_when_the_item_is_not_chase_eligible(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $item = $this->itemFor($firm, $client, DocumentRequestItemStatus::Approved);

        $result = $this->service->checkAndLog($item);

        $this->assertFalse($result->eligible);
        $this->assertSame(0, $item->chaseEvents()->count());
    }

    public function test_reminders_stop_once_waived(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $item = $this->itemFor($firm, $client, DocumentRequestItemStatus::Waived);

        $this->service->checkAndLog($item);

        $this->assertSame(0, $item->chaseEvents()->count());
    }

    public function test_no_event_is_logged_when_the_rule_is_paused(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $item = $this->itemFor($firm, $client, DocumentRequestItemStatus::Requested);
        $rule = DocumentChaseRule::factory()->forFirm($firm)->paused()->create();

        $result = $this->service->checkAndLog($item, $rule);

        $this->assertFalse($result->eligible);
        $this->assertSame('chase rule is paused', $result->reason);
        $this->assertSame(0, $item->chaseEvents()->count());
    }

    public function test_a_reminder_queued_event_is_logged_when_eligible(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
        ]);
        $item = $this->itemFor($firm, $client, DocumentRequestItemStatus::Requested);

        $result = $this->service->checkAndLog($item);

        $this->assertTrue($result->eligible);
        $this->assertSame(1, $item->chaseEvents()->where('event_type', 'reminder_queued')->count());
    }

    public function test_a_reminder_skipped_event_is_logged_when_not_eligible_but_status_is_chase_eligible(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        // no consent granted
        $item = $this->itemFor($firm, $client, DocumentRequestItemStatus::Requested);

        $result = $this->service->checkAndLog($item);

        $this->assertFalse($result->eligible);
        $this->assertSame(1, $item->chaseEvents()->where('event_type', 'reminder_skipped')->count());
    }

    public function test_needs_replacement_items_remain_chase_eligible(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
        ]);
        $item = $this->itemFor($firm, $client, DocumentRequestItemStatus::NeedsReplacement);

        $result = $this->service->checkAndLog($item);

        $this->assertTrue($result->eligible);
    }
}
