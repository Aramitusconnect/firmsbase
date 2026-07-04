<?php

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuppressionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SuppressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SuppressionService();
    }

    public function test_no_dedicated_suppression_table_exists_the_event_log_is_the_source_of_truth(): void
    {
        $firm = Firm::factory()->create();

        $this->assertFalse($this->service->isSuppressed($firm, 'client@example.com', ConsentChannel::Email));

        $this->service->recordBounce($firm, 'client@example.com', ConsentChannel::Email, (string) \Illuminate\Support\Str::uuid());

        $this->assertTrue($this->service->isSuppressed($firm, 'client@example.com', ConsentChannel::Email));
    }

    public function test_a_complaint_also_suppresses_the_recipient(): void
    {
        $firm = Firm::factory()->create();

        $this->service->recordComplaint($firm, 'client@example.com', ConsentChannel::Sms, (string) \Illuminate\Support\Str::uuid());

        $this->assertTrue($this->service->isSuppressed($firm, 'client@example.com', ConsentChannel::Sms));
    }

    public function test_suppression_is_scoped_to_firm_recipient_and_channel(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->service->recordBounce($firmA, 'client@example.com', ConsentChannel::Email, (string) \Illuminate\Support\Str::uuid());

        $this->assertFalse($this->service->isSuppressed($firmB, 'client@example.com', ConsentChannel::Email));
        $this->assertFalse($this->service->isSuppressed($firmA, 'client@example.com', ConsentChannel::Sms));
    }

    public function test_a_merely_sent_event_does_not_suppress(): void
    {
        $firm = Firm::factory()->create();
        NotificationEvent::factory()->create([
            'firm_id' => $firm->id,
            'recipient' => 'client@example.com',
            'channel' => ConsentChannel::Email,
            'status' => NotificationEventStatus::Sent,
        ]);

        $this->assertFalse($this->service->isSuppressed($firm, 'client@example.com', ConsentChannel::Email));
    }
}
