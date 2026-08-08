<?php

declare(strict_types=1);

namespace App\Filament\Firm\Concerns;

use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\Auth;

/**
 * ScopesQueriesToActiveFirm — shared helper for the Trust/IOLTA Filament
 * Action classes (mirrors CreateFlatFeeInvoiceAction's own private
 * `firmScoped()` helper, promoted to a reusable trait here purely
 * because the Trust module has far more Action classes than any prior
 * module and duplicating this same six-line helper ~25 times would be
 * pure boilerplate). Every closure that needs to read/query
 * firm-scoped data (Select options, pending-approval-event lookups,
 * etc.) or call a Trust*Service method must run inside
 * `firmScoped()`/`runInFirmContext()` — never bare — since Livewire's
 * AJAX update endpoint does not carry this app's
 * EstablishFirmTenantContext/ApplyTenantDatabaseContext middleware (see
 * WrapsRecordMutationInFirmContext's own docblock for the full
 * rationale), and three of the ten Trust tables
 * (trust_ledger_entries/trust_approval_events/matter_trust_balances)
 * deliberately carry NO BelongsToTenant global scope at all, so an
 * unwrapped query against those specific tables would not merely
 * under-filter, it would use no tenant context whatsoever.
 */
trait ScopesQueriesToActiveFirm
{
    private static function activeFirmUser(): ?FirmUser
    {
        return Auth::user()?->activeFirmUser();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private static function firmScoped(callable $callback)
    {
        $firmUser = self::activeFirmUser();

        if ($firmUser === null) {
            return null;
        }

        return app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, $callback);
    }
}
