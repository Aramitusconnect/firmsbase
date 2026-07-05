<?php

namespace App\Enums;

/**
 * AccountingExportLineStatus — Pending until AccountingExportSimulationService
 * runs; a line only ever moves Pending -> Exported or Pending -> Failed,
 * once, and is immutable afterward (SignatureEvent/DocumentHash-style
 * append-only guard on the model itself).
 */
enum AccountingExportLineStatus: string
{
    case Pending = 'pending';
    case Exported = 'exported';
    case Failed = 'failed';
}
