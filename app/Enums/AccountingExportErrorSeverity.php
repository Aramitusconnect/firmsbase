<?php

namespace App\Enums;

/**
 * AccountingExportErrorSeverity — mirrors Phase 8's ImportErrorSeverity
 * exactly. Error is a hard failure (the line did not export); Warning
 * is informational and does not by itself fail the line.
 */
enum AccountingExportErrorSeverity: string
{
    case Warning = 'warning';
    case Error = 'error';
}
