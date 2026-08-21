<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ChartOfAccountResource\Pages;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Filament\Firm\Resources\ChartOfAccountResource;
use App\Services\ChartOfAccountsService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * CreateChartOfAccount — the ONLY UI path that may create a
 * ChartOfAccount row; calls ChartOfAccountsService::create() directly,
 * never a bare `ChartOfAccount::create()`. A partial-unique-index
 * violation (an active account already claims the chosen purpose) is
 * translated into a normal Filament field-level validation error rather
 * than an uncaught 500 — the database constraint remains the real
 * enforcement, this page only surfaces it legibly.
 */
class CreateChartOfAccount extends CreateRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        try {
            return app(ChartOfAccountsService::class)->create(
                $firmUser->firm,
                $data['account_code'],
                $data['account_name'],
                ChartOfAccountType::from($data['account_type']),
                isset($data['purpose']) && $data['purpose'] !== null && $data['purpose'] !== ''
                    ? ChartOfAccountPurpose::from($data['purpose'])
                    : null,
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'chart_of_accounts_firm_active_purpose_unique')) {
                throw ValidationException::withMessages([
                    'data.purpose' => 'Your firm already has an active account for this purpose. Deactivate it first, or choose a different purpose.',
                ]);
            }

            throw $e;
        }
    }
}
