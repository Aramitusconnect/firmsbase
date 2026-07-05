<?php

namespace App\Services;

use App\Enums\AccountingExportErrorSeverity;
use App\Models\AccountingExportError;
use App\Models\AccountingExportLine;

/**
 * AccountingExportErrorLogger — the only writer of
 * accounting_export_errors. Append-only: never updates or deletes an
 * existing row itself, and the model's own booted() guard rejects any
 * attempt to do so regardless of caller (correction #9).
 */
class AccountingExportErrorLogger
{
    public function log(
        AccountingExportLine $line,
        ?string $field,
        string $message,
        AccountingExportErrorSeverity $severity = AccountingExportErrorSeverity::Error,
    ): AccountingExportError {
        return AccountingExportError::create([
            'accounting_export_line_id' => $line->id,
            'field' => $field,
            'severity' => $severity,
            'message' => $message,
        ]);
    }
}
