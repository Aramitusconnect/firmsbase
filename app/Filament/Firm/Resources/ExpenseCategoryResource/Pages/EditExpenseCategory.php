<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Firm\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * EditExpenseCategory — deliberately overrides `handleRecordUpdate()`
 * to call `ExpenseCategoryService::update()` directly, NEVER a bare
 * `$category->update()` (mirrors EditExpense's exact discipline — this
 * service is already the established "only writer of
 * expense_categories"). A duplicate-name rejection is translated into a
 * normal Filament field-level validation error, never an uncaught 500.
 */
class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        /** @var ExpenseCategory $record */
        try {
            return app(ExpenseCategoryService::class)->update($firmUser->firm, $record, $data['name']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }
    }
}
