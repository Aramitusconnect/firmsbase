<?php

namespace App\Services;

use App\Enums\ApiKeyScopeCode;
use App\Models\ApiKey;
use App\Models\ApiKeyScope;

/**
 * ApiKeyScopeService — the only writer of api_key_scopes.
 */
class ApiKeyScopeService
{
    public function grant(ApiKey $key, ApiKeyScopeCode $scope): ApiKeyScope
    {
        return ApiKeyScope::query()->firstOrCreate(
            ['api_key_id' => $key->id, 'scope_code' => $scope->value],
            ['granted_at' => now()],
        );
    }

    public function revoke(ApiKey $key, ApiKeyScopeCode $scope): void
    {
        ApiKeyScope::query()
            ->where('api_key_id', $key->id)
            ->where('scope_code', $scope->value)
            ->delete();
    }

    public function hasScope(ApiKey $key, ApiKeyScopeCode $scope): bool
    {
        return ApiKeyScope::query()
            ->where('api_key_id', $key->id)
            ->where('scope_code', $scope->value)
            ->exists();
    }

    /**
     * @return ApiKeyScopeCode[]
     */
    public function scopesFor(ApiKey $key): array
    {
        return $key->scopes()->get()->map(fn (ApiKeyScope $s) => $s->scope_code)->all();
    }
}
