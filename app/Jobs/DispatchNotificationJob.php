<?php

namespace App\Jobs;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Services\NotificationDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * DispatchNotificationJob — queued only after NotificationDispatchService
 * ::dispatch() has already passed template resolution, sender/domain
 * verification, and eligibility, and has recorded a Queued event. This
 * job never sends a real email/SMS/WhatsApp message (project rule: "No
 * real notification system yet") — it is a fakeable dispatch boundary
 * that records the terminal Sent event via
 * NotificationDispatchService::recordSent(). Constructor signature is
 * fixed by the exact call already made in NotificationDispatchService
 * ::dispatch(): (firmId, correlationId, templateId, channel value,
 * recipient, clientId, matterId).
 */
class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $firmId,
        public string $correlationId,
        public int $templateId,
        public string $channel,
        public string $recipient,
        public int $clientId,
        public ?int $matterId,
    ) {
    }

    public function handle(NotificationDispatchService $dispatcher): void
    {
        $firm = Firm::query()->find($this->firmId);

        if (! $firm) {
            return;
        }

        $channel = ConsentChannel::from($this->channel);

        // No real transport call. This is the fakeable boundary the
        // project's "no real notification system yet" rule requires —
        // the attempt is already fully classified and logged by
        // NotificationDispatchService::dispatch() before this job runs.
        $dispatcher->recordSent(
            $firm,
            $this->correlationId,
            $channel,
            $this->recipient,
            $this->templateId,
            $this->clientId,
            $this->matterId,
        );
    }
}
