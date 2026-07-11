<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Models\Firm;
use App\Models\User;

/**
 * ConsentService — the ONLY place communication_consents rows are
 * created or transitioned. Every state transition writes a paired
 * CommunicationConsentEvent row in the same transaction. client_id is
 * a plain nullable int (deferred FK — no Client model exists yet).
 * isGranted() is the enforcement check any future notification-sending
 * code must call before dispatching to a client on a given channel.
 *
 * Section 39A-3L, Checkpoint 11 — capture()/revoke() now wrap their
 * bodies in runWithFirmContext($firm, ...) instead of a bare
 * DB::transaction(...), since communication_consents is now FORCE-RLS
 * protected and this is the sole production write path for the table.
 * runWithFirmContext() opens its own transaction internally, so no
 * separate DB::transaction() call is needed. This same wrap also
 * covers the paired CommunicationConsentEvent::create() call in each
 * method body (communication_consent_events shares firm_id with its
 * parent consent row). isGranted() is deliberately left unwrapped — it
 * is a pure read helper, and DocumentChaseService::checkAndLog() (and
 * other callers) already invoke it from within their own active
 * runWithFirmContext() wrap; self-wrapping it here would reintroduce
 * the nested "decoy wrap" bug this arc has repeatedly avoided, since
 * the inner wrap's finally would clear the outer wrap's still-needed
 * context.
 */
class ConsentService
{
    public function capture(
        Firm $firm,
        ?int $clientId,
        ConsentChannel $channel,
        string $consentTextVersion,
        ?User $actor = null,
        ?string $capturedVia = null,
        ?string $capturedIp = null,
        ?\DateTimeInterface $expiresAt = null,
    ): CommunicationConsent {
        return (new TenantContextService())->runWithFirmContext($firm, function () use (
            $firm, $clientId, $channel, $consentTextVersion, $actor, $capturedVia, $capturedIp, $expiresAt
        ) {
            $existing = CommunicationConsent::query()
                ->where('firm_id', $firm->id)
                ->where('client_id', $clientId)
                ->where('channel', $channel->value)
                ->first();

            $previousStatus = $existing?->status?->value;

            $attributes = [
                'firm_id' => $firm->id,
                'client_id' => $clientId,
                'channel' => $channel,
                'status' => ConsentStatus::Granted,
                'consent_text_version' => $consentTextVersion,
                'granted_at' => now(),
                'revoked_at' => null,
                'expires_at' => $expiresAt,
                'captured_via' => $capturedVia,
                'captured_ip' => $capturedIp,
            ];

            $consent = $existing
                ? tap($existing)->update($attributes)
                : CommunicationConsent::create($attributes);

            CommunicationConsentEvent::create([
                'communication_consent_id' => $consent->id,
                'firm_id' => $firm->id,
                'action' => $existing ? 'recaptured' : 'captured',
                'previous_status' => $previousStatus,
                'new_status' => ConsentStatus::Granted->value,
                'consent_text_version' => $consentTextVersion,
                'actor_user_id' => $actor?->id,
                'source' => $capturedVia,
            ]);

            return $consent->fresh();
        });
    }

    public function revoke(
        Firm $firm,
        ?int $clientId,
        ConsentChannel $channel,
        ?User $actor = null,
        ?string $reason = null,
    ): CommunicationConsent {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $clientId, $channel, $actor, $reason) {
            $consent = CommunicationConsent::query()
                ->where('firm_id', $firm->id)
                ->where('client_id', $clientId)
                ->where('channel', $channel->value)
                ->first();

            if (! $consent) {
                throw new \RuntimeException('No communication consent exists for this firm/client/channel to revoke.');
            }

            $previousStatus = $consent->status->value;

            $consent->update([
                'status' => ConsentStatus::Revoked,
                'revoked_at' => now(),
            ]);

            CommunicationConsentEvent::create([
                'communication_consent_id' => $consent->id,
                'firm_id' => $firm->id,
                'action' => 'revoked',
                'previous_status' => $previousStatus,
                'new_status' => ConsentStatus::Revoked->value,
                'consent_text_version' => $consent->consent_text_version,
                'actor_user_id' => $actor?->id,
                'source' => $reason,
            ]);

            return $consent->fresh();
        });
    }

    public function isGranted(Firm $firm, ?int $clientId, ConsentChannel $channel): bool
    {
        $consent = CommunicationConsent::query()
            ->where('firm_id', $firm->id)
            ->where('client_id', $clientId)
            ->where('channel', $channel->value)
            ->first();

        return $consent?->isGranted() ?? false;
    }
}
