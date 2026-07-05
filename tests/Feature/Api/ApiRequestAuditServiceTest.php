<?php

namespace Tests\Feature\Api;

use App\Enums\ApiRequestStatus;
use App\Models\ApiKey;
use App\Services\ApiRequestAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRequestAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiRequestAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApiRequestAuditService();
    }

    public function test_log_writes_an_api_request_audit_row(): void
    {
        $key = ApiKey::factory()->create();

        $request = $this->service->log($key, 'clients.index', ApiRequestStatus::Success, 'GET');

        $this->assertDatabaseHas('api_requests', [
            'api_key_id' => $key->id,
            'firm_id' => $key->firm_id,
            'endpoint_identifier' => 'clients.index',
            'status' => 'success',
        ]);
        $this->assertSame(ApiRequestStatus::Success, $request->status);
    }

    public function test_recent_count_for_key_only_counts_within_window(): void
    {
        $key = ApiKey::factory()->create();
        $this->service->log($key, 'clients.index', ApiRequestStatus::Success);
        $this->service->log($key, 'clients.index', ApiRequestStatus::Success);

        $count = $this->service->recentCountForKey($key, now()->subMinute());

        $this->assertSame(2, $count);
    }

    public function test_every_request_is_logged_regardless_of_outcome(): void
    {
        $key = ApiKey::factory()->create();

        $this->service->log($key, 'clients.index', ApiRequestStatus::Forbidden);
        $this->service->log($key, 'clients.index', ApiRequestStatus::RateLimited);

        $this->assertDatabaseCount('api_requests', 2);
    }
}
