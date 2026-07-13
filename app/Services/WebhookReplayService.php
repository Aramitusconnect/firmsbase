<?php

namespace App\Services;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

/**
 * WebhookReplayService — the ONLY way to re-attempt a Failed/Exhausted
 * delivery (correction #9). Creates a brand-new webhook_deliveries row
 * referencing the same webhook_event_id; NEVER mutates the original
 * delivery or any of its webhook_delivery_attempts rows — the original
 * row's own attempt_count/status/history stays exactly as it was. Max 3
 * manual replays per original delivery (counted by
 * replayed_from_delivery_id = original.id); only FirmOwner/Attorney may
 * replay; requires the webhook entitlement still enabled; audited via
 * BOTH TimelineEventRecorder (firm-facing) and a security_events row
 * (platform-level), mirroring HighRiskPlatformChangePolicyService's
 * audit-insert shape.
 */
class WebhookReplayService
{
    private const MAX_REPLAYS_PER_ORIGINAL = 3;

    public function __construct(
        private readonly WebhookEntitlementPolicyService $entitlement,
        private readonly WebhookAccessPolicyService $accessPolicy,
        private readonly TenantSafeWebhookPolicyService $tenantSafePolicy,
        private readonly TimelineEventRecorder $timeline,
    ) {
    }

    public function replay(Firm $firm, WebhookDelivery $originalDelivery, FirmUser $actor): WebhookDelivery
    {
        $tenantContext = new TenantContextService();

        $newDelivery = $tenantContext->runWithFirmContext(
            $firm,
            function () use ($firm, $originalDelivery, $actor): WebhookDelivery {
                $this->entitlement->assertEnabled($firm);
                $this->tenantSafePolicy->assertWebhookDeliveryBelongsToFirm($originalDelivery, $firm);
                $this->accessPolicy->assertCanManage($actor);

                // Replaying a replay still counts against the delivery the
                // caller selected, and the new row retains one-hop lineage.
                $existingReplayCount = WebhookDelivery::query()
                    ->where('replayed_from_delivery_id', $originalDelivery->id)
                    ->count();

                if ($existingReplayCount >= self::MAX_REPLAYS_PER_ORIGINAL) {
                    throw new \RuntimeException(
                        "This delivery has already been replayed {$existingReplayCount} times; the maximum of ".
                        self::MAX_REPLAYS_PER_ORIGINAL.' manual replays per original delivery has been reached.'
                    );
                }

                return WebhookDelivery::create([
                    'firm_id' => $originalDelivery->firm_id,
                    'webhook_subscription_id' => $originalDelivery->webhook_subscription_id,
                    'webhook_event_id' => $originalDelivery->webhook_event_id,
                    'status' => WebhookDeliveryStatus::Pending,
                    'attempt_count' => 0,
                    'replayed_from_delivery_id' => $originalDelivery->id,
                    'replayed_by_firm_user_id' => $actor->id,
                    'replayed_at' => now(),
                ]);
            },
        );

        $tenantContext->runWithFirmContext(
            $firm,
            fn () => $this->timeline->record(
                $firm,
                'webhook_delivery_replayed',
                $newDelivery,
                $actor->user,
                [
                    'original_webhook_delivery_id' => $originalDelivery->id,
                    'new_webhook_delivery_id' => $newDelivery->id,
                ],
            ),
        );

        $tenantContext->runWithFirmContext(
            $firm,
            fn () => $this->auditSecurityEvent(
                $firm,
                $originalDelivery,
                $newDelivery,
                $actor,
            ),
        );

        return $newDelivery;
    }

    private function auditSecurityEvent(Firm $firm, WebhookDelivery $original, WebhookDelivery $replay, FirmUser $actor): void
    {
        DB::table('security_events')->insert([
            'firm_id' => $firm->id,
            'actor_type' => FirmUser::class,
            'actor_id' => $actor->id,
            'event_type' => 'webhook_delivery_replayed',
            'category' => 'webhook_replay',
            'metadata' => json_encode([
                'original_webhook_delivery_id' => $original->id,
                'new_webhook_delivery_id' => $replay->id,
            ]),
            'created_at' => now(),
        ]);
    }
}
