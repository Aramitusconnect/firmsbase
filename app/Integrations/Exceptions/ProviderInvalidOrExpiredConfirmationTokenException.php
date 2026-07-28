<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderInvalidOrExpiredConfirmationTokenException — thrown by
 * `App\Integrations\Billing\ProviderLiveBalanceConfirmationService::confirm()`
 * (checkpoint4-design-cost-control.md §5.3/§5.4) when the supplied
 * confirmation token does not match a still-live, single-use token
 * previously minted by `prepare()`. The token is consumed atomically
 * via `Cache::pull()` — a second `confirm()` call with the same token
 * (e.g. a second browser tab racing the first) finds nothing left to
 * pull and fails closed here, structurally preventing the
 * "repeated clicks / concurrent duplicate requests" failure mode the
 * product owner's own safeguard list names explicitly.
 */
final class ProviderInvalidOrExpiredConfirmationTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The supplied confirmation token is invalid, already consumed, or has expired.');
    }
}
