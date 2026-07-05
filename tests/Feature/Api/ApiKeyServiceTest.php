<?php

namespace Tests\Feature\Api;

use App\Enums\ApiKeyStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiKeyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApiKeyService();
    }

    public function test_create_returns_a_raw_secret_once_and_stores_only_a_hash(): void
    {
        $firm = Firm::factory()->create();
        $creator = FirmUser::factory()->forFirm($firm)->create();

        $result = $this->service->create('Integration key', 'firm', $firm, $creator);

        $this->assertNotEmpty($result['rawSecret']);
        $this->assertNotSame($result['rawSecret'], $result['key']->hashed_secret);
        $this->assertTrue(Hash::check($result['rawSecret'], $result['key']->hashed_secret));
        $this->assertDatabaseMissing('api_keys', ['hashed_secret' => $result['rawSecret']]);
    }

    public function test_created_key_can_be_scoped(): void
    {
        $firm = Firm::factory()->create();
        $creator = FirmUser::factory()->forFirm($firm)->create();
        $result = $this->service->create('Key', 'firm', $firm, $creator);

        $result['key']->scopes()->create(['scope_code' => \App\Enums\ApiKeyScopeCode::ClientsRead->value, 'granted_at' => now()]);

        $this->assertDatabaseHas('api_key_scopes', ['api_key_id' => $result['key']->id, 'scope_code' => 'clients_read']);
    }

    public function test_rotate_creates_a_new_key_and_marks_the_old_one_rotated(): void
    {
        $firm = Firm::factory()->create();
        $creator = FirmUser::factory()->forFirm($firm)->create();
        $original = $this->service->create('Key', 'firm', $firm, $creator)['key'];
        $original->scopes()->create(['scope_code' => \App\Enums\ApiKeyScopeCode::ClientsRead->value, 'granted_at' => now()]);

        $rotated = $this->service->rotate($original);

        $this->assertSame(ApiKeyStatus::Rotated, $original->fresh()->status);
        $this->assertSame(ApiKeyStatus::Active, $rotated['key']->status);
        $this->assertSame($original->id, $rotated['key']->rotated_from_id);
        $this->assertDatabaseHas('api_key_scopes', ['api_key_id' => $rotated['key']->id, 'scope_code' => 'clients_read']);
    }

    public function test_revoke_marks_the_key_revoked_with_a_reason(): void
    {
        $firm = Firm::factory()->create();
        $creator = FirmUser::factory()->forFirm($firm)->create();
        $key = $this->service->create('Key', 'firm', $firm, $creator)['key'];

        $revoked = $this->service->revoke($key, 'Compromised');

        $this->assertSame(ApiKeyStatus::Revoked, $revoked->status);
        $this->assertSame('Compromised', $revoked->revoked_reason);
    }

    public function test_last_used_tracking(): void
    {
        $firm = Firm::factory()->create();
        $creator = FirmUser::factory()->forFirm($firm)->create();
        $key = $this->service->create('Key', 'firm', $firm, $creator)['key'];

        $this->assertNull($key->last_used_at);

        $updated = $this->service->recordUsage($key);

        $this->assertNotNull($updated->last_used_at);
    }

    public function test_a_key_must_be_created_by_exactly_one_actor_type(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('Key', 'firm', $firm, null, null);
    }

    public function test_firm_type_key_requires_a_firm(): void
    {
        $creator = FirmUser::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('Key', 'firm', null, $creator);
    }

    public function test_platform_type_key_must_not_carry_a_firm(): void
    {
        $admin = \App\Models\PlatformAdmin::factory()->create();
        $firm = Firm::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('Key', 'platform', $firm, null, $admin);
    }
}
