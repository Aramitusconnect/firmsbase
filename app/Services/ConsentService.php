<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ConsentService — the ONLY place communication_consents rows are
 * created or transitioned. Every state transition writes a paired
 * CommunicationConsentEvent row in the same transaction. client_id is
 * a plain nullable int (deferred FK — no Client model exists yet).
 * isGranted() is the enforcement check any future notification-sending
 * code must call before dispatching to a client on a given channel.
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
        return DB::transaction(function () use (
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
        return DB::transaction(function () use ($firm, $clientId, $channel, $actor, $reason) {
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
