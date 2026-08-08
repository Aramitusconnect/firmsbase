<?php

declare(strict_types=1);

namespace App\Filament\Firm\Concerns;

use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * WrapsRecordMutationInFirmContext — shared fix for CreateRecord/
 * EditRecord pages against a FORCE-RLS, BelongsToTenant model.
 *
 * CONFIRMED PRODUCTION BUG PATTERN (already found and fixed for
 * Action closures throughout this panel — see e.g.
 * ViewFirmIntegration's and TriggerManualSyncAction's own docblocks):
 * every Livewire component method call in this app — including a
 * Filament CreateRecord/EditRecord page's `create()`/`save()` submit
 * handler — executes through Filament's shared `livewire/update` AJAX
 * endpoint, which carries only Filament's own fixed, package-boot-time
 * `Livewire::addPersistentMiddleware()` list
 * (vendor/filament/filament/src/FilamentServiceProvider.php) — never
 * this app's `EstablishFirmTenantContext`/`ApplyTenantDatabaseContext`
 * (wired only into `FirmPanelProvider`'s `authMiddleware`, which
 * governs page-LOAD routes only). Without this trait, a plain
 * `static::getModel()::create($data)` / `$record->update($data)` call
 * from inside a Create/EditRecord page runs with NO
 * `app.current_firm_id` PostgreSQL session setting at all, and every
 * model this panel manages is FORCE-RLS — the write is silently
 * denied (or, for create, fails validation/RLS on the firm_id BelongsToTenant
 * would otherwise have auto-filled from PHP-memory context that is
 * also absent here).
 *
 * `Auth::user()->activeFirmUser()` remains safe to call with no
 * ambient context, exactly as every existing Action in this panel
 * already relies on — it establishes only the narrow
 * `app.current_user_id` self-lookup setting internally (see
 * `TenantContextService::withUserContext()`), never
 * `app.current_firm_id`.
 *
 * Every write below re-fetches the record fresh by primary key inside
 * the wrap (TOCTOU discipline, matching every Action in this panel)
 * rather than trusting the page's mount()-time hydrated `$record`.
 */
trait WrapsRecordMutationInFirmContext
{
    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): Model => static::getModel()::create($data),
        );
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $data): Model {
                /** @var Model $fresh */
                $fresh = static::getModel()::query()->where('id', $record->getKey())->firstOrFail();
                $fresh->update($data);

                return $fresh->fresh();
            },
        );
    }
}
