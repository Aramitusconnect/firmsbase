<?php

namespace Tests\Feature\Activation;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EncryptionKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EncryptionKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private EncryptionKeyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EncryptionKeyService();
    }

    public function test_provision_creates_active_version_one_key(): void
    {
        $firm = Firm::factory()->create();

        $key = $this->service->provision($firm);

        $this->assertSame(1, $key->key_version);
        $this->assertSame(TenantEncryptionKeyStatus::Active, $key->status);

        // Section 39A-3L, Checkpoint 16: tenant_encryption_keys is now
        // FORCE RLS. provision() clears its own context wrap before
        // returning, so this bare assertDatabaseHas() (outside any
        // context) must be explicitly wrapped or it would incorrectly
        // find no matching row.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseHas('tenant_encryption_keys', [
                'firm_id' => $firm->id,
                'key_version' => 1,
                'status' => 'active',
            ]);
        });
    }

    public function test_provision_throws_when_firm_already_has_active_key(): void
    {
        $firm = Firm::factory()->create();
        $this->service->provision($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->provision($firm);
    }

    public function test_decrypt_active_key_returns_original_plaintext_material(): void
    {
        $firm = Firm::factory()->create();
        $this->service->provision($firm);

        $plaintext = $this->service->decryptActiveKey($firm);

        $this->assertNotEmpty($plaintext);

        // Section 39A-3L, Checkpoint 16: same bare-read wrap reasoning
        // as above.
        $encryptedKey = $this->runWithFirmContext(
            $firm,
            fn () => TenantEncryptionKey::where('firm_id', $firm->id)->first()->encrypted_key,
        );

        $this->assertNotSame($encryptedKey, $plaintext);
    }

    public function test_decrypt_active_key_throws_when_no_active_key_exists(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->decryptActiveKey($firm);
    }

    public function test_rotate_demotes_previous_key_and_creates_new_active_version(): void
    {
        $firm = Firm::factory()->create();
        $original = $this->service->provision($firm);

        $rotated = $this->service->rotate($firm);

        $this->assertSame(2, $rotated->key_version);
        $this->assertSame(TenantEncryptionKeyStatus::Active, $rotated->status);

        // Section 39A-3L, Checkpoint 16: same bare-read wrap reasoning
        // as above — both fresh() and the count query run outside any
        // context after rotate() returns.
        $this->runWithFirmContext($firm, function () use ($original) {
            $this->assertSame(TenantEncryptionKeyStatus::Rotated, $original->fresh()->status);
        });

        $activeCount = $this->runWithFirmContext(
            $firm,
            fn () => TenantEncryptionKey::where('firm_id', $firm->id)
                ->where('status', TenantEncryptionKeyStatus::Active->value)
                ->count(),
        );

        $this->assertSame(1, $activeCount);
    }

    public function test_rotate_produces_different_plaintext_key_material(): void
    {
        $firm = Firm::factory()->create();
        $this->service->provision($firm);
        $before = $this->service->decryptActiveKey($firm);

        $this->service->rotate($firm);
        $after = $this->service->decryptActiveKey($firm);

        $this->assertNotSame($before, $after);
    }
}
