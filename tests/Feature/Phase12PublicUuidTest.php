<?php

namespace Tests\Feature;

use App\Models\AccountingExportBatch;
use App\Models\AccountingExportLine;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Required: public UUID coverage for root models. matter_expenses and
 * accounting_export_errors are deliberately excluded — neither has a
 * uuid column (both are accessed only through their parent record),
 * matching the approved manifest's design.
 */
class Phase12PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uuidModelProvider')]
    public function test_model_generates_a_uuid_on_creation(string $modelClass): void
    {
        $instance = $modelClass::factory()->create();

        $this->assertNotEmpty($instance->uuid);
    }

    public static function uuidModelProvider(): array
    {
        return [
            [ChartOfAccount::class],
            [ExpenseCategory::class],
            [Expense::class],
            [ExpenseReceipt::class],
            [ExpenseApproval::class],
            [AccountingExportBatch::class],
            [AccountingExportLine::class],
        ];
    }

    public function test_uuid_is_immutable(): void
    {
        $expense = Expense::factory()->create();
        $original = $expense->uuid;

        $expense->update(['uuid' => (string) \Illuminate\Support\Str::uuid7()]);

        $this->assertSame($original, $expense->refresh()->uuid);
    }
}
