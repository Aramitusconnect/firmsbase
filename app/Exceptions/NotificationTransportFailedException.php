<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * NotificationTransportFailedException — post-9722e88 audit remediation.
 * Thrown by OutboundMailCorrelationService::correlate()/
 * PlatformNotificationCorrelationService::correlate() specifically when
 * the caller-supplied $send closure itself throws (the real ->notify()/
 * mail-transport call failed) — as distinct from a failure in the
 * correlation bookkeeping around it (creating the correlation row,
 * persisting the provider message id, etc.), which propagates as a
 * plain exception instead. CorrelatedPasswordResetSenderService catches
 * this specific type to report CorrelatedSendResult::TransportFailed
 * rather than CorrelatedSendResult::CorrelationFailed — the two require
 * different operational responses (a transport failure means the
 * recipient never got an email at all; a correlation failure can mean
 * the email went out with degraded/missing tracking).
 */
class NotificationTransportFailedException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The notification transport call itself failed: '.$previous->getMessage(), 0, $previous);
    }
}
