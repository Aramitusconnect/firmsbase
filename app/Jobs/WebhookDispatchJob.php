<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\FakeWebhookTransport;
use App\Services\WebhookDeliveryAttemptService;
use App\Services\WebhookSecretService;
use App\Services\WebhookSignatureService;
use App\Support\TenantAwareJobContext;
use App\ValueObjects\WebhookTransportResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * WebhookDispatchJob — the ONE approved Phase 14 job (correction #3),
 * the execution boundary that keeps core business workflows from ever
 * blocking on webhook delivery. Uses FakeWebhookTransport ONLY in this
 * phase — the constructor here type-hints the CONCRETE FakeWebhookTransport
 * class (not the WebhookTransportInterface), which Laravel's container
 * can auto-resolve without any AppServiceProvider/config binding
 * (correction #4's explicit instruction: "Use direct fake transport
 * injection/resolution in a testable way," "Do not modify
 * AppServiceProvider or config files for transport binding"). A future
 * real transport would require a deliberate, separately-approved change
 * to this type-hint (and everything else WebhookTransportInterface's
 * docblock requires) — not a container binding swap.
 *
 * handle() NEVER throws outward (correction #16): its entire body is
 * wrapped in try/catch. Every branch — success, transport failure, or
 * an internal error such as a missing active secret — either results in
 * exactly one webhook_delivery_attempts row via
 * WebhookDeliveryAttemptService, or (only when an attempt genuinely
 * cannot be attributed to any secret) is logged and skipped, never
 * thrown.
 *
 * The caller-supplied $firmId establishes the tenant context handle()
 * runs under (via TenantAwareJobContext) — required now that
 * webhook_deliveries/webhook_delivery_attempts are FORCE-RLS'd, since
 * the firm cannot be safely derived from an RLS-gated read against
 * webhook_deliveries itself.
 */
class WebhookDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(public int $webhookDeliveryId, public int $firmId)
    {
    }

    public function handle(
        FakeWebhookTransport $transport,
        WebhookSignatureService $signatureService,
        WebhookSecretService $secretService,
        WebhookDeliveryAttemptService $attemptService,
    ): void {
        // Whole-method wrap: the firm identity must be supplied
        // explicitly (see constructor) rather than derived from a
        // pre-context read against webhook_deliveries itself, since
        // that read would be RLS-gated by the very context it is
        // trying to establish. $delivery is declared inside the
        // closure so both the try and catch blocks (including the
        // catch's own fallback recordAttempt() write) share one
        // tenant context.
        $this->runInFirmContext($this->firmId, function () use ($transport, $signatureService, $secretService, $attemptService) {
            $delivery = null;

            try {
                $delivery = WebhookDelivery::query()->find($this->webhookDeliveryId);

                if (! $delivery) {
                    return;
                }

                $subscription = $delivery->subscription;
                $event = $delivery->event;
                $firm = $delivery->firm;
                $activeSecret = $subscription->activeSecret;

                if (! $activeSecret) {
                    Log::error('WebhookDispatchJob: no active secret for subscription; cannot sign delivery.', [
                        'webhook_delivery_id' => $delivery->id,
                        'webhook_subscription_id' => $subscription->id,
                    ]);

                    $attemptService->recordAttempt($delivery, WebhookTransportResult::failure(null, 'no active secret'));

                    return;
                }

                $rawSecret = $secretService->signingSecretFor($firm, $activeSecret);

                $canonicalPayload = json_encode($event->payload_json, JSON_UNESCAPED_SLASHES);
                $timestamp = (string) time();
                $signature = $signatureService->sign($rawSecret, $timestamp, $canonicalPayload);

                $headers = [
                    'X-FirmsBase-Event-Id' => $event->uuid,
                    'X-FirmsBase-Delivery-Id' => $delivery->uuid,
                    'X-FirmsBase-Timestamp' => $timestamp,
                    'X-FirmsBase-Signature' => $signature,
                ];

                $result = $transport->send($delivery, $canonicalPayload, $headers);

                $attemptService->recordAttempt($delivery, $result, $activeSecret);
            } catch (\Throwable $e) {
                Log::error('WebhookDispatchJob::handle() failed internally; never rethrown.', [
                    'webhook_delivery_id' => $this->webhookDeliveryId,
                    'exception' => $e->getMessage(),
                ]);

                if ($delivery) {
                    try {
                        $attemptService->recordAttempt($delivery, WebhookTransportResult::failure(null, 'internal dispatch error'));
                    } catch (\Throwable) {
                        // Absolutely never rethrow from handle() (correction #16),
                        // even if recording the failure attempt itself fails.
                    }
                }
            }
        });
    }
}
