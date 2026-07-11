<?php

namespace App\Services;

use App\Enums\TemplateUpgradeLogStatus;
use App\Enums\TemplateUpgradePreviewStatus;
use App\Models\TemplateUpgradeLog;
use App\Models\TemplateUpgradePreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TemplateUpgradeLogService — the only place template_upgrade_logs rows
 * are created. apply() is the sole path that actually calls the
 * EXISTING TemplatePackInstallationService::install() to change which
 * version is installed — applying always requires a preceding preview
 * (a preview row is the input to apply(), never a raw version).
 * rollback() NEVER mutates or deletes the original Applied row: it
 * calls install() with the ORIGINAL from_version and inserts a NEW
 * log row with status RolledBack and rollback_of_id pointing back,
 * mirroring Phase 5's MaintenanceWindowService::reschedule() supersede
 * pattern exactly.
 *
 * Section 39A-3L, Checkpoint 7 — template_upgrade_logs is now FORCE
 * RLS-enabled. install() already wraps its own entire body in
 * TenantContextService::runWithFirmContext() (Checkpoint 6), so it is
 * deliberately called OUTSIDE of any context here. Once install()
 * returns, apply()/rollback() open a SECOND, SEPARATE, SEQUENTIAL
 * runWithFirmContext() covering only their own direct writes
 * ($preview->update() / TemplateUpgradeLog::create()). Nesting the two
 * (wrapping install() itself in an outer context here) was tried and
 * empirically proven NOT to work: the inner wrap's own finally block
 * clears the PostgreSQL session/PHP-memory context the outer wrap
 * still expects to be active for its own subsequent writes.
 */
class TemplateUpgradeLogService
{
    public function __construct(private TemplatePackInstallationService $installationService)
    {
    }

    public function apply(TemplateUpgradePreview $preview, ?User $actor = null): TemplateUpgradeLog
    {
        return DB::transaction(function () use ($preview, $actor) {
            $this->installationService->install($preview->firm, $preview->toVersion);

            $tenantContext = new TenantContextService();

            return $tenantContext->runWithFirmContext($preview->firm, function () use ($preview, $actor) {
                $preview->update(['status' => TemplateUpgradePreviewStatus::Applied]);

                return TemplateUpgradeLog::create([
                    'firm_id' => $preview->firm_id,
                    'installed_template_pack_id' => $preview->installed_template_pack_id,
                    'from_version_id' => $preview->from_version_id,
                    'to_version_id' => $preview->to_version_id,
                    'status' => TemplateUpgradeLogStatus::Applied,
                    'applied_at' => now(),
                    'applied_by' => $actor?->id,
                ]);
            });
        });
    }

    /**
     * @throws \RuntimeException if the log being rolled back has no
     *         from_version (nothing to revert to).
     */
    public function rollback(TemplateUpgradeLog $log, ?User $actor = null): TemplateUpgradeLog
    {
        if (! $log->from_version_id) {
            throw new \RuntimeException("Log #{$log->id} has no from_version_id — there is nothing to roll back to.");
        }

        return DB::transaction(function () use ($log, $actor) {
            $this->installationService->install($log->firm, $log->fromVersion);

            $tenantContext = new TenantContextService();

            return $tenantContext->runWithFirmContext($log->firm, function () use ($log, $actor) {
                return TemplateUpgradeLog::create([
                    'firm_id' => $log->firm_id,
                    'installed_template_pack_id' => $log->installed_template_pack_id,
                    'from_version_id' => $log->to_version_id,
                    'to_version_id' => $log->from_version_id,
                    'status' => TemplateUpgradeLogStatus::RolledBack,
                    'applied_at' => now(),
                    'applied_by' => $actor?->id,
                    'rollback_of_id' => $log->id,
                ]);
            });
        });
    }
}
