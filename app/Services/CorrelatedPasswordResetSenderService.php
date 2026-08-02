<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\CorrelatedSendResult;
use App\Exceptions\NotificationTransportFailedException;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CorrelatedPasswordResetSenderService — post-9722e88 audit remediation.
 * The single, dedicated application service every password-reset/
 * invitation send goes through. Never throws — every failure mode is
 * caught internally and reported via the CorrelatedSendResult it
 * returns, so a caller that must preserve a void, non-throwing contract
 * (User::sendPasswordResetNotification()'s public, anti-enumeration-
 * sensitive path) can call this and discard the result, while a caller
 * that needs to know what happened (FirmProvisioningService) can
 * inspect it directly.
 *
 * Firm-owned email (an owner invitation, or any later reset once a
 * User's active FirmUser resolves) ALWAYS requires an exact firm
 * correlation — $firm is a required, non-nullable parameter for that
 * path; there is no platform-level or uncorrelated fallback available
 * to it at all. The platform-scope path below exists ONLY for the
 * narrow, distinct case where $firm itself is null (see
 * sendForUnresolvedFirm()) — and even there, every one of its own
 * failure modes (missing HMAC key, a suppression-check error, a
 * correlation-row DB failure) now fails closed rather than falling back
 * to a bare, uncorrelated ->notify() call.
 */
class CorrelatedPasswordResetSenderService
{
    public function __construct(
        private readonly OutboundMailCorrelationService $firmCorrelation,
        private readonly PlatformNotificationCorrelationService $platformCorrelation,
    ) {}

    /**
     * Firm-owned email — an exact firm is already known/resolved by the
     * caller. No platform-level or uncorrelated fallback exists on this
     * path at all: any failure before the send is CorrelationFailed,
     * any failure in the send itself is TransportFailed, and either way
     * no email goes out.
     *
     * @param  \Closure(string $correlationId): Notification  $makeNotification
     */
    public function sendForFirm(
        Model $notifiable,
        Firm $firm,
        ConsentChannel $channel,
        string $recipient,
        \Closure $makeNotification,
    ): CorrelatedSendResult {
        try {
            $this->firmCorrelation->correlate(
                $firm,
                $channel,
                $recipient,
                fn (string $correlationId) => $notifiable->notify($makeNotification($correlationId)),
            );

            return CorrelatedSendResult::Sent;
        } catch (NotificationTransportFailedException $e) {
            Log::critical('correlated_password_reset_transport_failed', [
                'account_type' => $notifiable::class,
                'account_id' => $notifiable->getKey(),
                'firm_id' => $firm->id,
                'exception' => $e->getPrevious()?->getMessage(),
            ]);

            return CorrelatedSendResult::TransportFailed;
        } catch (Throwable $e) {
            Log::critical('correlated_password_reset_firm_correlation_failed', [
                'account_type' => $notifiable::class,
                'account_id' => $notifiable->getKey(),
                'firm_id' => $firm->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return CorrelatedSendResult::CorrelationFailed;
        }
    }

    /**
     * The narrow platform-scope path — used ONLY when the caller could
     * not resolve an owning firm at all (e.g. a User mid-deactivation,
     * or a ClientPortalUser whose Client record is detached). Every
     * failure mode here — a missing/invalid HMAC key, a suppression-
     * check error, a correlation-row DB failure, or the send itself
     * failing — now fails closed: no email is sent, and this method
     * never falls back to an uncorrelated ->notify() call.
     *
     * @param  \Closure(string $correlationId): Notification  $makeNotification
     */
    public function sendForUnresolvedFirm(
        Model $notifiable,
        string $notificationType,
        string $recipient,
        \Closure $makeNotification,
    ): CorrelatedSendResult {
        try {
            if ($this->platformCorrelation->isRecipientSuppressed($recipient)) {
                Log::warning('correlated_password_reset_platform_suppressed', [
                    'account_type' => $notifiable::class,
                    'account_id' => $notifiable->getKey(),
                    'notification_type' => $notificationType,
                ]);

                return CorrelatedSendResult::Suppressed;
            }
        } catch (Throwable $e) {
            Log::critical('correlated_password_reset_platform_suppression_check_failed', [
                'account_type' => $notifiable::class,
                'account_id' => $notifiable->getKey(),
                'notification_type' => $notificationType,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return CorrelatedSendResult::CorrelationFailed;
        }

        try {
            $this->platformCorrelation->correlate(
                $notifiable::class,
                (int) $notifiable->getKey(),
                $notificationType,
                $recipient,
                fn (string $correlationId) => $notifiable->notify($makeNotification($correlationId)),
            );

            return CorrelatedSendResult::Sent;
        } catch (NotificationTransportFailedException $e) {
            Log::critical('correlated_password_reset_transport_failed', [
                'account_type' => $notifiable::class,
                'account_id' => $notifiable->getKey(),
                'notification_type' => $notificationType,
                'exception' => $e->getPrevious()?->getMessage(),
            ]);

            return CorrelatedSendResult::TransportFailed;
        } catch (Throwable $e) {
            Log::critical('correlated_password_reset_platform_correlation_failed', [
                'account_type' => $notifiable::class,
                'account_id' => $notifiable->getKey(),
                'notification_type' => $notificationType,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return CorrelatedSendResult::CorrelationFailed;
        }
    }
}
