<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FailedJob — Phase 4 (FirmsVault Platform Admin Control Center,
 * "Operations"). A thin, READ-ONLY Eloquent wrapper around Laravel's
 * own `failed_jobs` table (see database/migrations/0001_01_01_000002_create_jobs_table.php)
 * — no new table, no new migration. This model exists purely so the
 * Queues & Jobs admin page can use an ordinary Filament ->query()
 * table (search/sort/filter/paginate) instead of a raw ->records()
 * closure, since `failed_jobs` carries no RLS at all (System/global,
 * exactly like `jobs` — see QueueHealthService's own docblock) and
 * needs no per-firm handling of any kind.
 *
 * Never written to directly by application code — row creation is
 * Laravel's own queue worker failure path; row deletion/retry go
 * through QueueHealthService::retryFailedJob()/deleteFailedJob(),
 * which use the framework's own `queue.failer` binding (uuid-keyed),
 * never this model's own save()/delete(). $guarded = ['*'] enforces
 * this at the mass-assignment level.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
