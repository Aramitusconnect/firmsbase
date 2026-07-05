<?php

namespace Tests\Feature\Api;

use App\Enums\ApiKeyScopeCode;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Models\ApiKey;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\ApiAccessPolicyService;
use App\Services\ApiKeyScopeService;
use App\Services\ApiRequestAuditService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiAccessPolicyService $service;
    private ApiKeyScopeService $scopeService;
    private EntitlementService $entitlementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlementService = new EntitlementService();
        $this->scopeService = new ApiKeyScopeService();
        $this->service = new ApiAccessPolicyService(
            $this->entitlementService,
            $this->scopeService,
            new ApiRequestAuditService(),
        );
    }

    public function test_scope_check_fails_when_key_lacks_the_scope(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlementService->setForSource($firm, 'api', EntitlementSource::AdminOverride, true);
        $key = ApiKey::factory()->firmType($firm)->create();

        $decision = $this->service->canUseScope($key, ApiKeyScopeCode::ClientsRead);

        $this->assertFalse($decision->allowed);
    }

    public function test_scope_check_respects_firm_entitlement_for_the_api_module(): void
    {
        $firm = Firm::factory()->create();
        $key = ApiKey::factory()->firmType($firm)->create();
        $this->scopeService->grant($key, ApiKeyScopeCode::ClientsRead);

        // No entitlement granted for 'api' — must be denied even though
        // the key itself carries the scope.
        $decision = $this->service->canUseScope($key, ApiKeyScopeCode::ClientsRead);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('entitled', $decision->reason);
    }

    public function test_scope_check_succeeds_when_entitled_and_scoped(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlementService->setForSource($firm, 'api', EntitlementSource::AdminOverride, true);
        $key = ApiKey::factory()->firmType($firm)->create();
        $this->scopeService->grant($key, ApiKeyScopeCode::ClientsRead);

        $decision = $this->service->canUseScope($key, ApiKeyScopeCode::ClientsRead);

        $this->assertTrue($decision->allowed);
    }

    public function test_rate_limit_policy_blocks_once_the_limit_is_exceeded(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlementService->setForSource($firm, 'api', EntitlementSource::AdminOverride, true);
        $key = ApiKey::factory()->firmType($firm)->create(['rate_limit_per_minute' => 2]);
        $this->scopeService->grant($key, ApiKeyScopeCode::ClientsRead);

        $auditService = new ApiRequestAuditService();
        $auditService->log($key, 'clients.index', \App\Enums\ApiRequestStatus::Success);
        $auditService->log($key, 'clients.index', \App\Enums\ApiRequestStatus::Success);

        $decision = $this->service->canUseScope($key, ApiKeyScopeCode::ClientsRead);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('rate limit', $decision->reason);
    }

    public function test_can_manage_api_keys_is_a_firm_user_role_allowlist(): void
    {
        $owner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create();
        $paralegal = FirmUser::factory()->role(FirmUserRole::Paralegal)->create();

        $this->assertTrue($this->service->canManageApiKeys($owner)->allowed);
        $this->assertFalse($this->service->canManageApiKeys($paralegal)->allowed);
    }

    public function test_expired_key_is_denied(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlementService->setForSource($firm, 'api', EntitlementSource::AdminOverride, true);
        $key = ApiKey::factory()->firmType($firm)->create(['expires_at' => now()->subDay()]);
        $this->scopeService->grant($key, ApiKeyScopeCode::ClientsRead);

        $decision = $this->service->canUseScope($key, ApiKeyScopeCode::ClientsRead);

        $this->assertFalse($decision->allowed);
    }
}
