<?php

namespace Tests\Feature\Api;

use App\Enums\ApiKeyScopeCode;
use App\Models\ApiKey;
use App\Services\ApiKeyScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiKeyScopeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApiKeyScopeService();
    }

    public function test_grant_and_has_scope(): void
    {
        $key = ApiKey::factory()->create();

        $this->service->grant($key, ApiKeyScopeCode::MattersRead);

        $this->assertTrue($this->service->hasScope($key, ApiKeyScopeCode::MattersRead));
        $this->assertFalse($this->service->hasScope($key, ApiKeyScopeCode::MattersWrite));
    }

    public function test_granting_twice_is_idempotent(): void
    {
        $key = ApiKey::factory()->create();

        $this->service->grant($key, ApiKeyScopeCode::ImportManage);
        $this->service->grant($key, ApiKeyScopeCode::ImportManage);

        $this->assertSame(1, \App\Models\ApiKeyScope::query()->where('api_key_id', $key->id)->count());
    }

    public function test_revoke_removes_the_scope(): void
    {
        $key = ApiKey::factory()->create();
        $this->service->grant($key, ApiKeyScopeCode::ExportManage);

        $this->service->revoke($key, ApiKeyScopeCode::ExportManage);

        $this->assertFalse($this->service->hasScope($key, ApiKeyScopeCode::ExportManage));
    }

    public function test_scopes_for_returns_all_granted_scopes(): void
    {
        $key = ApiKey::factory()->create();
        $this->service->grant($key, ApiKeyScopeCode::ClientsRead);
        $this->service->grant($key, ApiKeyScopeCode::ClientsWrite);

        $scopes = $this->service->scopesFor($key);

        $this->assertCount(2, $scopes);
        $this->assertContains(ApiKeyScopeCode::ClientsRead, $scopes);
    }
}
