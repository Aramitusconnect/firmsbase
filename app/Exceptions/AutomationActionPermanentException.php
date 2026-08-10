<?php

namespace App\Exceptions;

/**
 * AutomationActionPermanentException — Event-Driven Automation Engine,
 * item 10. Thrown by an AutomationActionHandler for a failure that
 * retrying can never fix (an authorization failure, an invalid business
 * state, a canonical service's own validation rejecting the call) — the
 * execution engine marks the action Failed immediately, never retries.
 */
class AutomationActionPermanentException extends \RuntimeException {}
