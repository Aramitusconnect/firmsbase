<?php

namespace App\Services;

use App\Enums\WebhookDeliveryAttemptOutcome;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookSecret;
use App\ValueObjects\WebhookTransportResult;

/**
 * WebhookDeliveryAttemptService — the only writer of
 * webhook_delivery_attempts (append-only, correction #13). Every fake
 * transport outcome becomes exactly one attempt row (correction #16).
 * Records webhook_secret_id (correction #7) — whichever secret was
 * ACTIVE at the moment of this attempt, since secrets are rotatable and
 * an old attempt must remain explainable after a later rotation.
 * response_snippet is length-capped and must never contain secret/
 * ciphertext/signature material — callers must never pass raw secret
 * material into $result->responseSnippet in the first place; this
 * service does not itself scan for secret leakage, the discipline lives
 * in what WebhookDispatchJob/FakeWebhookTransport ever construct.
 *
 * On failure/timeout: if attempt_count is still under max_attempts,
 * status -> Pending with next_attempt_at set via
 * WebhookRetryPolicyService; at/over max_attempts, status -> Exhausted
 * (terminal). On success: status -> Delivered.
 */
class WebhookDeliveryAttemptService
{
    public function __construct(private readonly WebhookRetryPolicyService $retryPolicy)
    {
    }

    public function recordAttempt(WebhookDelivery $delivery, WebhookTransportResult $result, ?WebhookSecret $signedWithSecret = null): WebhookDeliveryAttempt
    {
        $attemptNumber = $delivery->attempt_count + 1;

        $attempt = WebhookDeliveryAttempt::create([
            'firm_id' => $delivery->firm_id,
            'webhook_delivery_id' => $delivery->id,
            'webhook_secret_id' => $signedWithSecret?->id,
            'attempt_number' => $attemptNumber,
            'outcome' => $result->outcome,
            'http_status_code' => $result->httpStatusCode,
            'response_snippet' => $result->responseSnippet === null ? null : substr($result->responseSnippet, 0, 500),
            'attempted_at' => now(),
        ]);

        $retryPolicyConfig = $delivery->subscription->retry_policy_json ?? WebhookRetryPolicyService::DEFAULT_RETRY_POLICY;

        if ($result->outcome === WebhookDeliveryAttemptOutcome::Success) {
            $delivery->update([
                'status' => WebhookDeliveryStatus::Delivered,
                'attempt_count' => $attemptNumber,
                'last_attempted_at' => now(),
                'next_attempt_at' => null,
            ]);
        } elseif ($this->retryPolicy->isExhausted($attemptNumber, $retryPolicyConfig)) {
            $delivery->update([
                'status' => WebhookDeliveryStatus::Exhausted,
                'attempt_count' => $attemptNumber,
                'last_attempted_at' => now(),
                'next_attempt_at' => null,
            ]);
        } else {
            $delaySeconds = $this->retryPolicy->nextAttemptDelaySeconds($attemptNumber, $retryPolicyConfig);

            $delivery->update([
                'status' => WebhookDeliveryStatus::Pending,
                'attempt_count' => $attemptNumber,
                'last_attempted_at' => now(),
                'next_attempt_at' => now()->addSeconds($delaySeconds),
            ]);
        }

        return $attempt;
    }
}
