<?php

namespace Database\Factories;

use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebauthnCredential>
 */
class WebauthnCredentialFactory extends Factory
{
    protected $model = WebauthnCredential::class;

    public function definition(): array
    {
        return [
            'platform_admin_id' => PlatformAdmin::factory(),
            'name' => $this->faker->words(2, true).' authenticator',
            'credential_id' => base64_encode(random_bytes(32)),
            'public_key' => base64_encode(random_bytes(77)),
            'attestation_type' => 'none',
            'transports' => ['internal'],
            'aaguid' => Str::uuid()->toString(),
            'sign_count' => 0,
            'backup_eligible' => false,
            'backup_status' => false,
            'last_used_at' => null,
        ];
    }
}
