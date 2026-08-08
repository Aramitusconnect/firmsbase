<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Pages;

use App\Filament\Firm\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * EditExpense — deliberately overrides `handleRecordUpdate()` to call
 * `ExpenseService::editWhileDraft()` directly, NEVER a bare
 * `$expense->update()` — unlike EditTask/EditDeadline/EditTimeEntry
 * (which have no dedicated update-service to route through), Expense
 * DOES have one, and this mission's rule is explicit: route through it
 * whenever it exists. `editWhileDraft()` itself already refuses to run
 * against a non-Draft expense (its own guard) — this page's Policy
 * (`ExpensePolicy::update()`) additionally gates the EDIT PAGE ITSELF
 * to Draft-only rows so a non-Draft expense never even renders an edit
 * form implying a save is possible.
 *
 * The form's `amount` field is entered/edited in dollars;
 * `mutateFormDataBeforeFill()` converts the stored `amount_cents` back
 * to dollars for display, and `handleRecordUpdate()` converts back to
 * cents on save — mirrors CreateExpense's own conversion exactly.
 */
class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = ((int) ($data['amount_cents'] ?? 0)) / 100;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $data, $firmUser): Expense {
                /** @var Expense $fresh */
                $fresh = Expense::query()->where('id', $record->getKey())->firstOrFail();

                return app(ExpenseService::class)->editWhileDraft($firmUser->firm, $fresh, [
                    'vendor_name' => $data['vendor_name'],
                    'amount_cents' => (int) round(((float) $data['amount']) * 100),
                    'expense_date' => Carbon::parse($data['expense_date']),
                    'reimbursable' => (bool) ($data['reimbursable'] ?? false),
                    'description' => $data['description'] ?? null,
                    'expense_category_id' => $data['expense_category_id'],
                    'matter_id' => $data['matter_id'] ?? null,
                ]);
            },
        );
    }
}
