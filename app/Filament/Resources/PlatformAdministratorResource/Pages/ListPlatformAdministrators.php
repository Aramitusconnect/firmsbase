<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformAdministratorResource\Pages;

use App\Filament\Resources\PlatformAdministratorResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * ListPlatformAdministrators — no header actions: no Create form (see
 * PlatformAdministratorResource's own docblock for why). Mutations
 * happen on the View page, per-record, not here.
 *
 * Phase 1 correction — last-login bounding: PlatformAdministratorResource::
 * table()'s "Last login" column needs a batched (never per-row)
 * actor_id => MAX(created_at) map, but the original implementation
 * computed that map for EVERY platform_admins row before pagination was
 * even applied — an unbounded scan of the whole security_events table
 * on every render, regardless of how many rows the current page
 * actually shows.
 *
 * paginateTableQuery() is the correct hook for this: HasRecords::
 * getTableRecords() calls getFilteredSortedTableQuery() (search/filter/
 * sort already applied) and THEN paginateTableQuery($query) to apply
 * the actual LIMIT/OFFSET — overriding it here lets this class see
 * exactly the Model rows that will render on THIS page (and only those)
 * before computing the last-login map, with zero N+1 (one extra query
 * total, not one per row and not one per admin outside the page).
 * $lastLoginAtByAdminId is a plain public array (Livewire-serializable,
 * matching this codebase's own established "public array $snapshot"
 * widget-data convention — see PlatformRecentPrivilegedActivityWidget)
 * read by the "last_login_at" column's state() closure via the
 * standard Filament `$livewire` closure-injection parameter (Filament\
 * Tables\Columns\Column::resolveDefaultClosureDependencyForEvaluationByName()).
 */
class ListPlatformAdministrators extends ListRecords
{
    protected static string $resource = PlatformAdministratorResource::class;

    /**
     * Populated by paginateTableQuery() below, scoped to exactly the
     * admin IDs on the current rendered page. Never read directly by
     * anything other than the "last_login_at" column's state() closure.
     *
     * @var array<int, string>
     */
    public array $lastLoginAtByAdminId = [];

    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        $paginator = parent::paginateTableQuery($query);

        $adminIds = collect($paginator->items())->pluck('id');

        $this->lastLoginAtByAdminId = PlatformAdministratorResource::lastLoginAtByAdminId($adminIds);

        return $paginator;
    }
}
