<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * ProviderConnectionMismatchException — FirmsVault Pay Gate A3
 * (v1.4 §28). The provider resource belongs to a DIFFERENT provider
 * account/connection than the one this command or event is being
 * processed under. Always fail closed: no financial mutation of any
 * kind may follow this exception.
 */
class ProviderConnectionMismatchException extends RuntimeException {}
