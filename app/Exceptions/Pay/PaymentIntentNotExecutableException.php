<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * PaymentIntentNotExecutableException — FirmsVault Pay Gate A2
 * (v1.4 §17/§18). The intent is not in a state that may be executed:
 * not frozen, already superseded, cancelled, or its allocations do not
 * satisfy the completeness invariant.
 *
 * Deliberately distinct from TrustExecutionDisabledException so an
 * operator can tell "this instruction is malformed/not ready" apart
 * from "this instruction is well-formed but trust may never execute".
 */
class PaymentIntentNotExecutableException extends RuntimeException {}
