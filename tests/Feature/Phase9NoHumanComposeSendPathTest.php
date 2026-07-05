<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms Phase 9 introduces no send flow at all (project rule —
 * "sending through Gmail/Outlook is not Phase 9... must not create
 * new human-compose send flows"). No email_send_requests table, no
 * EmailSendRequestService, no send-oriented route/controller.
 */
class Phase9NoHumanComposeSendPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_email_send_requests_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('email_send_requests'));
    }

    public function test_no_email_send_request_model_or_service_file_exists(): void
    {
        $this->assertFalse(class_exists(\App\Models\EmailSendRequest::class));
        $this->assertFalse(class_exists(\App\Services\EmailSendRequestService::class));
    }

    public function test_no_send_oriented_service_file_exists_on_disk(): void
    {
        $serviceFiles = glob(app_path('Services/*.php')) ?: [];

        foreach ($serviceFiles as $file) {
            $this->assertStringNotContainsString('SendRequest', basename($file));
            $this->assertStringNotContainsString('ComposeEmail', basename($file));
        }
    }

    public function test_email_module_has_no_routes_or_controllers(): void
    {
        $this->assertFalse(is_dir(app_path('Http/Controllers/Email')));

        $controllerFiles = glob(app_path('Http/Controllers/*.php')) ?: [];
        foreach ($controllerFiles as $file) {
            $this->assertStringNotContainsString('Email', basename($file));
        }
    }
}
