<?php

namespace Database\Factories;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<TenantEncryptionKey>
 */
class TenantEncryptionKeyFactory extends Factory
{
    protected $model = TenantEncryptionKey::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'key_version' => 1,
            'status' => TenantEncryptionKeyStatus::Active,
            'encrypted_key' => fn () => Crypt::encryptString(base64_encode(random_bytes(32))),
            'destroyed_at' => null,
            'destruction_request_id' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => ['status' => TenantEncryptionKeyStatus::Rotated]);
    }

    public function destroyed(): static
    {
        return $this->state(fn () => [
            'status' => TenantEncryptionKeyStatus::Destroyed,
            'destroyed_at' => now(),
        ]);
    }
}
