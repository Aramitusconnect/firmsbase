<?php

namespace App\Services;

use App\Enums\ApiRequestStatus;
use App\Models\ApiKey;
use App\Models\ApiRequest;

/**
 * ApiRequestAuditService — the only writer of api_requests. Every API
 * access attempt must be logged here (project rule: "API requests must
 * be audit logged"), regardless of outcome.
 */
class ApiRequestAuditService
{
    public function log(
        ?ApiKey $key,
        string $endpointIdentifier,
        ApiRequestStatus $status,
        ?string $method = null,
        ?string $scopeUsed = null,
        ?string $ipAddress = null,
        ?int $responseCode = null,
    ): ApiRequest {
        return ApiRequest::create([
            'api_key_id' => $key?->id,
            'firm_id' => $key?->firm_id,
            'endpoint_identifier' => $endpointIdentifier,
            'method' => $method,
            'status' => $status,
            'scope_used' => $scopeUsed,
            'ip_address' => $ipAddress,
            'response_code' => $responseCode,
            'occurred_at' => now(),
        ]);
    }

    public function recentCountForKey(ApiKey $key, \DateTimeInterface $since): int
    {
        return ApiRequest::query()
            ->where('api_key_id', $key->id)
            ->where('occurred_at', '>=', $since)
            ->count();
    }
}
