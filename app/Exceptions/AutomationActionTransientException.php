<?php

namespace App\Exceptions;

/**
 * AutomationActionTransientException — Event-Driven Automation Engine,
 * item 10. Thrown by an AutomationActionHandler for a failure that is
 * plausibly temporary (a queue/infra hiccup, a lock contention, a
 * dependency briefly unavailable) — the execution engine schedules a
 * retry (RetryScheduled) rather than giving up, up to the action
 * execution's own max_attempts.
 */
class AutomationActionTransientException extends \RuntimeException {}
