<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\ClientCommunicationPreference;
use App\Models\PaymentPlanInstallment;
use App\Services\ConsentService;
use App\ValueObjects\DunningEligibility;

/**
 * PaymentPlanDunningService — checks eligibility and logs the attempt
 * only. No real notification system exists yet (Phase 4 owns
 * notification_events/templates/delivery); this service never sends
 * anything. Reuses Phase 1/2's ConsentService and
 * ClientCommunicationPreference exclusively — no second consent
 * system is created here (project rule).
 *
 * Applies one fixed default policy in code (no dunning_policies table
 * — see the payment_plans migration doc comment for why). Automatically
 * pauses whenever the installment's parent plan is not Active — this
 * covers both Paused and Renegotiated plans without special-casing
 * either, since renegotiation always flips the old plan's status away
 * from Active.
 */
class PaymentPlanDunningService
{
    public function __construct(
        private ConsentService $consent,
        private TimelineEventRecorder $timeline,
    ) {
    }

    public function checkAndLog(PaymentPlanInstallment $installment, ConsentChannel $channel = ConsentChannel::Email): DunningEligibility
    {
        $plan = $installment->paymentPlan;
        $firm = $plan->firm;
        $client = $plan->client;

        if (! $plan->isDunningEligibleStatus()) {
            // Deliberately does not log an event here — no dunning
            // attempt actually occurred (nothing to pause or resume).
            return new DunningEligibility(
                eligible: false,
                reason: "payment plan is not active (status={$plan->status->value})",
            );
        }

        if (! in_array($installment->status, [
            PaymentPlanInstallmentStatus::Due,
            PaymentPlanInstallmentStatus::Missed,
            PaymentPlanInstallmentStatus::PartiallyPaid,
        ], true)) {
            return new DunningEligibility(
                eligible: false,
                reason: "installment is not in a dunnable status (status={$installment->status->value})",
            );
        }

        $preference = ClientCommunicationPreference::query()
            ->where('firm_id', $firm->id)
            ->where('client_id', $client->id)
            ->first();

        if ($preference?->do_not_contact) {
            return $this->logAndReturn($installment, false, 'do_not_contact flag is set', $channel, $client);
        }

        if (! $this->consent->isGranted($firm, $client->id, $channel)) {
            return $this->logAndReturn($installment, false, "no granted consent for channel {$channel->value}", $channel, $client);
        }

        return $this->logAndReturn($installment, true, null, $channel, $client);
    }

    private function logAndReturn(
        PaymentPlanInstallment $installment,
        bool $eligible,
        ?string $reason,
        ConsentChannel $channel,
        \App\Models\Client $client,
    ): DunningEligibility {
        $plan = $installment->paymentPlan;

        (new TenantContextService())->runWithFirmContext($plan->firm, function () use ($installment, $plan, $eligible, $reason, $channel) {
            $installment->update([
                'dunning_state' => $eligible ? 'reminder_queued' : 'reminder_skipped',
            ]);

            $plan->events()->create([
                'firm_id' => $plan->firm_id,
                'event_type' => $eligible ? 'dunning_reminder_queued' : 'dunning_reminder_skipped',
                'metadata_json' => [
                    'payment_plan_installment_id' => $installment->id,
                    'channel' => $channel->value,
                    'reason' => $reason,
                ],
            ]);

            $this->timeline->record($plan->firm, $eligible ? 'dunning_reminder_queued' : 'dunning_reminder_skipped', $installment);
        });

        return new DunningEligibility(
            eligible: $eligible,
            reason: $reason,
            channel: $channel,
            clientLanguage: $client->preferred_language,
            clientTimezone: $client->preferred_timezone,
        );
    }
}
