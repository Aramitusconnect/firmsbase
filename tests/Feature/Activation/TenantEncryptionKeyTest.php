<?php

namespace Tests\Feature\Activation;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantEncryptionKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $key = TenantEncryptionKey::factory()->create();

        $this->assertDatabaseHas('tenant_encryption_keys', ['id' => $key->id]);
        $this->assertSame(TenantEncryptionKeyStatus::Active, $key->status);
    }

    public function test_encrypted_key_is_hidden_from_array_and_json(): void
    {
        $key = TenantEncryptionKey::factory()->create();

        $this->assertArrayNotHasKey('encrypted_key', $key->toArray());
        $this->assertStringNotContainsString('encrypted_key', $key->toJson());
    }

    public function test_only_one_active_key_per_firm_at_database_level(): void
    {
        $firm = Firm::factory()->create();
        TenantEncryptionKey::factory()->forFirm($firm)->create(['key_version' => 1]);

        $this->expectException(QueryException::class);

        TenantEncryptionKey::factory()->forFirm($firm)->create(['key_version' => 2]);
    }

    public function test_rotated_and_active_keys_can_coexist_for_same_firm(): void
    {
        $firm = Firm::factory()->create();
        TenantEncryptionKey::factory()->forFirm($firm)->rotated()->create(['key_version' => 1]);
        $active = TenantEncryptionKey::factory()->forFirm($firm)->create(['key_version' => 2]);

        $this->assertDatabaseCount('tenant_encryption_keys', 2);
        $this->assertTrue($active->isActive());
    }

    public function test_unique_firm_id_key_version(): void
    {
        $firm = Firm::factory()->create();
        TenantEncryptionKey::factory()->forFirm($firm)->rotated()->create(['key_version' => 1]);

        $this->expectException(QueryException::class);

        TenantEncryptionKey::factory()->forFirm($firm)->rotated()->create(['key_version' => 1]);
    }

    public function test_no_uuid_column_exists(): void
    {
        $key = TenantEncryptionKey::factory()->create();

        $this->assertArrayNotHasKey('uuid', $key->getAttributes());
    }
}
