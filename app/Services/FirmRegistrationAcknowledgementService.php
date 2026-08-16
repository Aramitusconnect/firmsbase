<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLead;
use App\Notifications\FirmRegistrationReceivedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the one acknowledgement email a public firm registration request earns.
 *
 * Routed through PlatformNotificationCorrelationService — the same governed
 * path CorrelatedPasswordResetSenderService uses for platform-scoped mail —
 * rather than calling Mail/SES directly, so this send gets the suppression
 * check, correlation row, recipient fingerprinting and provider-message-id
 * handling every other outbound email gets. A PlatformLead has no Firm, so the
 * firm-scoped OutboundMailCorrelationService::correlate() is not usable here;
 * the platform correlator exists for exactly this case.
 *
 * Two deliberate properties:
 *
 *  - Sending NEVER fails the request. The lead is already committed by the time
 *    this runs; throwing here would turn a captured registration into a 500 and
 *    lose it. A failed send is logged and the caller continues.
 *  - Duplicate suppression is time-boxed and derived from data already on the
 *    lead, so it needs no schema. Re-submitting the same email within the
 *    window records the new lead but sends no second acknowledgement.
 */
class FirmRegistrationAcknowledgementService
{
    /**
     * Long enough to absorb an impatient double-submit or a refreshed form,
     * short enough that a genuine second enquiry days later is still answered.
     */
    private const DUPLICATE_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly PlatformNotificationCorrelationService $correlation,
    ) {}

    public function sendFor(PlatformLead $lead): bool
    {
        $recipient = trim((string) $lead->contact_email);

        if ($recipient === '') {
            return false;
        }

        if ($this->alreadyAcknowledgedRecently($lead, $recipient)) {
            Log::info('firm_registration_acknowledgement_suppressed_duplicate', [
                'lead_id' => $lead->id,
                'recipient_fingerprint' => $this->safeFingerprint($recipient),
                'window_minutes' => self::DUPLICATE_WINDOW_MINUTES,
            ]);

            return false;
        }

        try {
            $this->correlation->correlate(
                PlatformLead::class,
                (int) $lead->id,
                'firm_registration_received',
                $recipient,
                fn (string $correlationId) => Notification::route('mail', $recipient)
                    ->notify(new FirmRegistrationReceivedNotification($lead, $correlationId)),
            );

            return true;
        } catch (Throwable $e) {
            // Never surfaced to the submitter: their registration WAS captured,
            // and telling them it failed would be false. Recorded loudly enough
            // that an operator can see acknowledgements are not going out.
            Log::error('firm_registration_acknowledgement_send_failed', [
                'lead_id' => $lead->id,
                'recipient_fingerprint' => $this->safeFingerprint($recipient),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Deduplicate against the leads table itself — an earlier lead with the
     * same email inside the window means an acknowledgement has already gone
     * out for this person. No new column, no new table.
     */
    private function alreadyAcknowledgedRecently(PlatformLead $lead, string $recipient): bool
    {
        return PlatformLead::query()
            ->where('id', '<', $lead->id)
            ->where('source', $lead->source)
            ->whereRaw('lower(contact_email) = ?', [mb_strtolower($recipient)])
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->exists();
    }

    /**
     * Operational logs get a one-way fingerprint, never the address itself.
     */
    private function safeFingerprint(string $recipient): string
    {
        try {
            return $this->correlation->fingerprintFor($recipient);
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
