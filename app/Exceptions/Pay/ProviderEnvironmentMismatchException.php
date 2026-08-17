<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * ProviderEnvironmentMismatchException — FirmsVault Pay Gate A3
 * (v1.4 §29). A sandbox/test resource presented through a mismatched
 * environment context (or vice versa). Always fail closed: no financial
 * mutation may follow. This proves the core invariant BEFORE any real
 * Finix environment wiring exists.
 */
class ProviderEnvironmentMismatchException extends RuntimeException {}
