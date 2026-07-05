<?php

namespace App\Enums;

/**
 * FormMappingSourceEntity — form_mapping_rules.source_entity. The
 * exact, closed set of entities DeterministicFieldResolutionService is
 * allowed to read from. Adding a new source entity requires a code
 * change to that service's fixed allowlist, never a config value —
 * this is what keeps resolution deterministic and injection-free.
 */
enum FormMappingSourceEntity: string
{
    case Client = 'client';
    case Matter = 'matter';
    case IntakeSubmission = 'intake_submission';
    case Contact = 'contact';
    case Party = 'party';
}
