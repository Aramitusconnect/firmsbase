<?php

namespace Tests\Feature\Accounting\Export;

use App\Models\AccountingExportError;
use App\Models\AccountingExportLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required (correction #9): accounting_export_errors is append-only.
 */
class AccountingExportErrorAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_row_cannot_be_updated(): void
    {
        $line = AccountingExportLine::factory()->create();
        $error = AccountingExportError::factory()->forLine($line)->create();

        $this->expectException(\LogicException::class);
        $error->update(['message' => 'changed']);
    }

    public function test_error_row_cannot_be_deleted(): void
    {
        $line = AccountingExportLine::factory()->create();
        $error = AccountingExportError::factory()->forLine($line)->create();

        $this->expectException(\LogicException::class);
        $error->delete();
    }
}
