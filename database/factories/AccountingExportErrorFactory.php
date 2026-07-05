<?php

namespace Database\Factories;

use App\Enums\AccountingExportErrorSeverity;
use App\Models\AccountingExportError;
use App\Models\AccountingExportLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingExportError>
 */
class AccountingExportErrorFactory extends Factory
{
    protected $model = AccountingExportError::class;

    public function definition(): array
    {
        return [
            'accounting_export_line_id' => AccountingExportLine::factory(),
            'field' => 'chart_of_accounts_id',
            'severity' => AccountingExportErrorSeverity::Error,
            'message' => 'No chart of accounts mapping was found for this record.',
        ];
    }

    public function forLine(AccountingExportLine $line): static
    {
        return $this->state(fn () => ['accounting_export_line_id' => $line->id]);
    }
}
