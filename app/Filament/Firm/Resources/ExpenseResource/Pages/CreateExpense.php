<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Pages;

use App\Filament\Firm\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\ExpenseService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateExpense — the ONLY UI path that may create an Expense row;
 * calls ExpenseService::create() directly, NEVER a bare
 * `Expense::create()` (this mission's explicit rule — mirrors
 * CreateTask/CreateDeadline/CreateTimeEntry's exact discipline).
 *
 * The form's `amount` field is entered in dollars and converted to a
 * whole-cent integer here (`amount_cents` is the real column —
 * Expense's own casts() declares it `integer`).
 *
 * Tenant-context wrap matches every other create page in this panel —
 * ExpenseService's own internal runWithFirmContext() call (and its own
 * assertExpensesEnabled()/assertExpenseCategoryBelongsToFirm() checks,
 * called first, unwrapped, inside the service itself) is safe/
 * re-entrant nested inside this one.
 */
class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): Expense {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $category = ExpenseCategory::query()->where('id', $data['expense_category_id'])->firstOrFail();
                $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                    ? Matter::query()->where('id', $data['matter_id'])->first()
                    : null;

                return app(ExpenseService::class)->create(
                    firm: $firm,
                    category: $category,
                    createdBy: $firmUser,
                    vendorName: $data['vendor_name'],
                    amountCents: (int) round(((float) $data['amount']) * 100),
                    expenseDate: Carbon::parse($data['expense_date']),
                    reimbursable: (bool) ($data['reimbursable'] ?? false),
                    matter: $matter,
                    description: $data['description'] ?? null,
                );
            },
        );
    }
}
