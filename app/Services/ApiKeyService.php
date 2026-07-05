<?php

namespace App\Services;

use App\Enums\ApiKeyStatus;
use App\Models\ApiKey;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ApiKeyService — the only writer of api_keys. The raw secret is
 * generated here, returned ONCE to the caller inside the return array
 * of create()/rotate(), and is never persisted anywhere — only
 * hashed_secret (via Hash::make(), the framework's own hasher) and
 * last_four are stored. Exactly one of firm-actor/platform-actor must
 * be supplied for created_by (approved correction #1) — enforced here,
 * not at the DB layer.
 */
class ApiKeyService
{
    /**
     * @return array{key: ApiKey, rawSecret: string}
     */
    public function create(
        string $name,
        string $keyType,
        ?Firm $firm = null,
        ?FirmUser $createdByFirmUser = null,
        ?PlatformAdmin $createdByPlatformAdmin = null,
        ?int $rateLimitPerMinute = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $this->assertExactlyOneActor($createdByFirmUser, $createdByPlatformAdmin);

        if ($keyType === 'firm' && $firm === null) {
            throw new \InvalidArgumentException('A firm-type API key requires a firm.');
        }

        if ($keyType === 'platform' && $firm !== null) {
            throw new \InvalidArgumentException('A platform-type API key must not carry a firm_id.');
        }

        $rawSecret = 'fbk_'.Str::random(40);

        $key = ApiKey::create([
            'firm_id' => $firm?->id,
            'key_type' => $keyType,
            'name' => $name,
            'hashed_secret' => Hash::make($rawSecret),
            'last_four' => substr($rawSecret, -4),
            'status' => ApiKeyStatus::Active,
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'expires_at' => $expiresAt,
            'created_by_firm_user_id' => $createdByFirmUser?->id,
            'created_by_platform_admin_id' => $createdByPlatformAdmin?->id,
        ]);

        return ['key' => $key, 'rawSecret' => $rawSecret];
    }

    /**
     * Creates a new key carrying the same firm/type/scopes lineage,
     * marks the old key Rotated (not Revoked — rotation is a distinct
     * lifecycle event), and links the new key back via rotated_from_id.
     *
     * @return array{key: ApiKey, rawSecret: string}
     */
    public function rotate(ApiKey $existing): array
    {
        $rawSecret = 'fbk_'.Str::random(40);

        $newKey = ApiKey::create([
            'firm_id' => $existing->firm_id,
            'key_type' => $existing->key_type,
            'name' => $existing->name,
            'hashed_secret' => Hash::make($rawSecret),
            'last_four' => substr($rawSecret, -4),
            'status' => ApiKeyStatus::Active,
            'rate_limit_per_minute' => $existing->rate_limit_per_minute,
            'rotated_from_id' => $existing->id,
            'created_by_firm_user_id' => $existing->created_by_firm_user_id,
            'created_by_platform_admin_id' => $existing->created_by_platform_admin_id,
        ]);

        foreach ($existing->scopes as $scope) {
            $newKey->scopes()->create(['scope_code' => $scope->scope_code, 'granted_at' => now()]);
        }

        $existing->update(['status' => ApiKeyStatus::Rotated]);

        return ['key' => $newKey, 'rawSecret' => $rawSecret];
    }

    public function revoke(ApiKey $key, string $reason): ApiKey
    {
        $key->update([
            'status' => ApiKeyStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);

        return $key->fresh();
    }

    public function verifySecret(ApiKey $key, string $rawSecret): bool
    {
        return Hash::check($rawSecret, $key->hashed_secret);
    }

    public function recordUsage(ApiKey $key): ApiKey
    {
        $key->update(['last_used_at' => now()]);

        return $key->fresh();
    }

    private function assertExactlyOneActor(?FirmUser $firmUser, ?PlatformAdmin $platformAdmin): void
    {
        if ($firmUser === null && $platformAdmin === null) {
            throw new \InvalidArgumentException('An API key must be created by either a firm user or a platform admin.');
        }

        if ($firmUser !== null && $platformAdmin !== null) {
            throw new \InvalidArgumentException('An API key cannot be created by both a firm user and a platform admin.');
        }
    }
}
