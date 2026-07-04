<?php

namespace App\Enums;

/**
 * DocumentVersionStatus — document_versions.status. No exact value
 * list given by the PDF — recommendation. Exactly one version per
 * document may be Current at a time; DocumentReplacementService is the
 * only place that changes this.
 */
enum DocumentVersionStatus: string
{
    case Current = 'current';
    case Superseded = 'superseded';
    case Deleted = 'deleted';
}
