<?php

namespace App\Services;

use App\Enums\WebhookEventType;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\Firm;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Log;

/**
 * WebhookEventRecorderService — the only writer of webhook_events.
 * record() must NEVER break a core workflow (correction #16): its
 * entire body is wrapped in try/catch, any internal failure is logged
 * and null is returned, never thrown. One event row is created per
 * business event (never one per subscription — correction #11), then
 * fanned out to N webhook_deliveries via WebhookDeliveryService, one
 * per matching Active subscription whose event_types includes this
 * event type.
 *
 * This service builds the event and its fan-out; it deliberately does
 * NOT call any business workflow itself (MatterService, InvoiceService,
 * etc.) — wiring actual call sites into Phase 1-13 services is
 * out of this phase's approved scope (manifest §2/§10 — only Firm.php
 * and the module_catalog seed may be touched).
 */
class WebhookEventRecorderService
{
    public function __construct(
        private readonly WebhookEntitlementPolicyService $entitlement,
        private readonly WebhookPayloadBuilderService $payloadBuilder,
        private readonly WebhookDeliveryService $deliveryService,
    ) {}

    public function record(Firm $firm, WebhookEventType $type, object $subject): ?WebhookEvent
    {
        try {
            if (! $this->entitlement->isEnabled($firm)) {
                return null;
            }

            // Whole method body wrapped as one atomic unit (build,
            // create(), subscription read, and enqueue() fan-out) —
            // fixes a decoy-wrap gap found during Wave 11 Phase 2 review
            // that previously left everything but build() unwrapped.
            return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $type, $subject) {
                $payload = $this->payloadBuilder->build($type, $subject);

                $event = WebhookEvent::create([
                    'firm_id' => $firm->id,
                    'event_type' => $type,
                    'subject_type' => get_class($subject),
                    'subject_id' => $subject->id ?? null,
                    'payload_json' => $payload,
                    'occurred_at' => now(),
                ]);

                $matchingSubscriptions = WebhookSubscription::query()
                    ->where('firm_id', $firm->id)
                    ->where('status', WebhookSubscriptionStatus::Active->value)
                    ->get()
                    ->filter(fn (WebhookSubscription $subscription) => in_array($type->value, $subscription->event_types ?? [], true));

                foreach ($matchingSubscriptions as $subscription) {
                    $this->deliveryService->enqueue($event, $subscription);
                }

                return $event;
            });
        } catch (\Throwable $e) {
            Log::error('WebhookEventRecorderService::record() failed internally; core workflow continues unaffected.', [
                'firm_id' => $firm->id,
                'event_type' => $type->value,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
