<?php

namespace App\Enums;

/**
 * HashAlgorithm — typed rather than a raw string column, since this
 * value feeds directly into an evidentiary claim. Sha256 is the only
 * supported value in this phase; the enum exists to make future
 * algorithm additions a typed, reviewable change rather than an
 * unconstrained string.
 */
enum HashAlgorithm: string
{
    case Sha256 = 'sha256';
}
