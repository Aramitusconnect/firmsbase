<?php

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationTemplateService();
    }

    public function test_resolve_returns_the_global_default_when_no_firm_override_exists(): void
    {
        $firm = Firm::factory()->create();
        $global = $this->service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Please upload your document.');

        $resolved = $this->service->resolve($firm, 'document_reminder', ConsentChannel::Email);

        $this->assertTrue($resolved->is($global));
    }

    public function test_resolve_prefers_a_firm_override_over_the_global_default(): void
    {
        $firm = Firm::factory()->create();
        $this->service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body.');
        $override = $this->service->createFirmOverride($firm, 'document_reminder', ConsentChannel::Email, 'Firm-specific body.');

        $resolved = $this->runWithFirmContext($firm, fn () => $this->service->resolve($firm, 'document_reminder', ConsentChannel::Email));

        $this->assertTrue($resolved->is($override));
    }

    public function test_resolve_ignores_an_archived_template(): void
    {
        $firm = Firm::factory()->create();
        $global = $this->service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body.');
        $this->service->archive($global);

        $resolved = $this->service->resolve($firm, 'document_reminder', ConsentChannel::Email);

        $this->assertNull($resolved);
    }

    public function test_only_one_global_default_may_exist_per_key_channel_language(): void
    {
        NotificationTemplate::factory()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        NotificationTemplate::factory()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);
    }

    public function test_only_one_firm_override_may_exist_per_firm_key_channel_language(): void
    {
        $firm = Firm::factory()->create();
        NotificationTemplate::factory()->forFirm($firm)->create([
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        NotificationTemplate::factory()->forFirm($firm)->create([
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);
    }

    public function test_is_global_default_is_true_only_when_firm_id_is_null(): void
    {
        $firm = Firm::factory()->create();
        $global = NotificationTemplate::factory()->create(['firm_id' => null]);
        $override = NotificationTemplate::factory()->forFirm($firm)->create();

        $this->assertTrue($global->isGlobalDefault());
        $this->assertFalse($override->isGlobalDefault());
    }
}
