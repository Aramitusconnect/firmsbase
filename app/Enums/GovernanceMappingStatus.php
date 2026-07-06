<?php

namespace App\Enums;

/**
 * GovernanceMappingStatus — the closed set of classifications a
 * cross-cutting governance mapping item may hold. Declarative only:
 * this enum makes no compliance claim and enforces nothing by itself.
 */
enum GovernanceMappingStatus: string
{
    case Implemented = 'implemented';
    case PreparedNotEnforced = 'prepared_not_enforced';
    case PartiallyImplemented = 'partially_implemented';
    case FrameworkDefaultOnly = 'framework_default_only';
    case NotFound = 'not_found';
    case NotApplicableYet = 'not_applicable_yet';
}
