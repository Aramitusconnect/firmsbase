<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Firm\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CreateExpenseCategory — the ONLY UI path that may create an
 * ExpenseCategory row; calls ExpenseCategoryService::create() directly,
 * NEVER a bare `ExpenseCategory::create()` (mirrors CreateExpense's
 * exact discipline — that service is already the established "only
 * writer of expense_categories"). A duplicate-name rejection from the
 * service is translated into a normal Filament field-level validation
 * error (never an uncaught 500) — the service's own uniqueness check is
 * the only place this rule is enforced, this page just surfaces it.
 */
class CreateExpenseCategory extends CreateRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        try {
            return app(ExpenseCategoryService::class)->create($firmUser->firm, $data['name']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }
    }
}
