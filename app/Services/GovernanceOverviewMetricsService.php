<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeletionRequestStatus;
use App\Enums\ExportJobStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LegalHoldStatus;
use App\Enums\MigrationProjectStatus;
use App\Enums\OffboardingRequestStatus;
use App\Models\DeletionRequest;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\LegalHold;
use App\Models\MigrationProject;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\ValueObjects\GovernanceAttentionItem;
use App\ValueObjects\GovernanceMetric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * GovernanceOverviewMetricsService — the read model behind the
 * Governance Overview page. Read-only: performs no writes, dispatches
 * no jobs, and mutates no governance state.
 *
 * CROSS-FIRM QUERY PATTERN. Every governance table aggregated here
 * (legal_holds, deletion_requests, export_jobs, import_batches,
 * migration_projects, offboarding_requests) carries permanent FORCE ROW
 * LEVEL SECURITY, and no policy lets a single session read across every
 * firm's rows at once. This service therefore uses the same per-firm
 * loop under runWithFirmContext() that every other cross-firm platform
 * directory service in this repository uses (see
 * PlatformLegalHoldDirectoryService's own docblock for the canonical
 * statement of that constraint). It does NOT disable RLS, drop FORCE,
 * use BYPASSRLS, connect as a table owner, or open a second unscoped
 * connection.
 *
 * Cost is bounded and set-based: ONE context switch per firm, and
 * within it one GROUP BY aggregate per governance table — never a query
 * per row. Nothing is loaded into PHP except the grouped counts
 * themselves, so the overview's memory profile does not grow with the
 * number of governance records.
 *
 * TRUTHFULNESS. Every number returned here is either a real count or an
 * explicit non-value (GovernanceMetric::notMonitored() /
 * ::notSupported()). This service never emits 0 to stand in for missing
 * evidence. The four places that matters most on current HEAD:
 *
 *   1. Retention sweep history. RetentionSweepAuditLogger writes flat
 *      log lines to storage/logs/integration-retention-sweep.log and
 *      nothing else — there is no sweep-history table, and no scheduler
 *      -run table either. "Last successful sweep" and "failed sweeps"
 *      are therefore NOT_MONITORED, not 0. A registered schedule entry
 *      is not evidence that a sweep ever executed, so the scheduled
 *      cadence is reported separately and labelled as configuration.
 *
 *   2. Legal hold review/expiry. legal_holds has no review_date and no
 *      expires_at column, and LegalHoldStatus has exactly two cases
 *      (Active, Released). Holds do not expire and are never released
 *      automatically. Review and expiry metrics are NOT_SUPPORTED
 *      rather than 0, so the overview cannot imply a hold will lapse on
 *      its own.
 *
 *   3. Export downloads. export_jobs stores no file path, size,
 *      checksum, encryption state, expiry, or download record — export
 *      "completion" is a manifest/status milestone, not an archive.
 *      Download-related metrics are NOT_SUPPORTED, and the overview
 *      must not offer a download affordance anywhere.
 *
 *   4. Deletion execution. DeletionRequestStatus has no Executed case;
 *      the terminal state is ReadyForExecution and
 *      DeletionGovernanceService never physically deletes the target
 *      row. "In execution" and "completed" are NOT_SUPPORTED rather
 *      than 0.
 */
final class GovernanceOverviewMetricsService
{
    /**
     * The scheduled retention sweep cadence, read from the same
     * schedule entry bootstrap/app.php registers. Reported as
     * CONFIGURATION, never as evidence that a sweep ran.
     */
    public const SWEEP_SCHEDULE_DESCRIPTION = 'Daily (integrations:retention:sweep)';

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly RetentionGovernanceRegistryService $retentionRegistry,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessGovernance($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access governance data.');
        }
    }

    /**
     * @return array{
     *     retention: array<int, GovernanceMetric>,
     *     legal_holds: array<int, GovernanceMetric>,
     *     deletion: array<int, GovernanceMetric>,
     *     exports: array<int, GovernanceMetric>,
     *     imports: array<int, GovernanceMetric>,
     *     migrations: array<int, GovernanceMetric>,
     *     offboarding: array<int, GovernanceMetric>,
     *     attention: array<int, GovernanceAttentionItem>,
     * }
     */
    public function summary(PlatformAdmin $admin): array
    {
        $this->assertCanAccess($admin);

        $counts = $this->statusCountsAcrossFirms();

        return [
            'retention' => $this->retentionMetrics(),
            'legal_holds' => $this->legalHoldMetrics($counts['legal_holds']),
            'deletion' => $this->deletionMetrics($counts['deletion_requests']),
            'exports' => $this->exportMetrics($counts['export_jobs']),
            'imports' => $this->importMetrics($counts['import_batches']),
            'migrations' => $this->migrationMetrics($counts['migration_projects']),
            'offboarding' => $this->offboardingMetrics($counts['offboarding_requests']),
            'attention' => $this->attentionItems($counts),
        ];
    }

    // -----------------------------------------------------------------
    // Cross-firm aggregation
    // -----------------------------------------------------------------

    /**
     * One context switch per firm; one GROUP BY aggregate per governance
     * table inside it. Returns per-table maps of status value => count,
     * summed across every firm.
     *
     * @return array<string, array<string, int>>
     */
    private function statusCountsAcrossFirms(): array
    {
        $totals = [
            'legal_holds' => [],
            'deletion_requests' => [],
            'export_jobs' => [],
            'import_batches' => [],
            'migration_projects' => [],
            'offboarding_requests' => [],
        ];

        foreach ($this->firms() as $firm) {
            $perFirm = $this->tenantContext->runWithFirmContext($firm, fn (): array => [
                'legal_holds' => $this->groupedStatusCounts(LegalHold::query()),
                'deletion_requests' => $this->groupedStatusCounts(DeletionRequest::query()),
                'export_jobs' => $this->groupedStatusCounts(ExportJob::query()),
                'import_batches' => $this->groupedStatusCounts(ImportBatch::query()),
                'migration_projects' => $this->groupedStatusCounts(MigrationProject::query()),
                'offboarding_requests' => $this->groupedStatusCounts(OffboardingRequest::query()),
            ]);

            foreach ($perFirm as $table => $statusCounts) {
                foreach ($statusCounts as $status => $count) {
                    $totals[$table][$status] = ($totals[$table][$status] ?? 0) + $count;
                }
            }
        }

        return $totals;
    }

    /**
     * A single set-based GROUP BY — never one query per row, and never
     * a full row fetch that would pull governance records into PHP just
     * to count them.
     *
     * @return array<string, int>
     */
    private function groupedStatusCounts(Builder $query): array
    {
        return $query
            ->getQuery()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Full rows deliberately, not a narrowed select: TenantContextResolver
     * refuses a partially-loaded Firm model (it needs deployment_mode to
     * resolve a context), and every other cross-firm directory service in
     * this repository loads firms the same way.
     *
     * @return Collection<int, Firm>
     */
    private function firms(): Collection
    {
        return Firm::query()->orderBy('id')->get();
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function countOf(array $counts, string ...$statuses): int
    {
        $total = 0;

        foreach ($statuses as $status) {
            $total += $counts[$status] ?? 0;
        }

        return $total;
    }

    // -----------------------------------------------------------------
    // Per-domain metrics
    // -----------------------------------------------------------------

    /**
     * @return array<int, GovernanceMetric>
     */
    private function retentionMetrics(): array
    {
        $categories = $this->retentionRegistry->categories();

        $configured = 0;
        $failSafe = 0;
        $outOfScope = 0;
        $notYetApplicable = 0;

        foreach ($categories as $entry) {
            match ($entry['status']) {
                RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT,
                RetentionGovernanceRegistryService::STATUS_CONFIGURED_PLACEHOLDER => $configured++,
                RetentionGovernanceRegistryService::STATUS_NOT_CONFIGURED_FAIL_SAFE => $failSafe++,
                RetentionGovernanceRegistryService::STATUS_OUT_OF_SCOPE_SNAPSHOT => $outOfScope++,
                default => $notYetApplicable++,
            };
        }

        return [
            GovernanceMetric::available('Configured categories', $configured),
            GovernanceMetric::available('Fail-safe (no default, sweep no-ops)', $failSafe),
            GovernanceMetric::available('Out of scope (no retention window applies)', $outOfScope),
            GovernanceMetric::available(
                'Legal hold coverage unresolved',
                count($this->retentionRegistry->categoriesWithUnresolvedLegalHoldCoverage())
            ),
            GovernanceMetric::notMonitored(
                'Last successful sweep',
                'RetentionSweepAuditLogger writes flat log lines to '
                .'storage/logs/integration-retention-sweep.log only. No sweep-history table and no '
                .'scheduler-run table exist, so no sweep execution can be confirmed from queryable data. '
                .'A registered schedule entry is configuration, not evidence that a sweep ran.'
            ),
            GovernanceMetric::notMonitored(
                'Failed sweeps',
                'Same flat-log-only limitation. Reporting 0 here would assert that sweeps ran and none '
                .'failed, which no durable evidence supports.'
            ),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, GovernanceMetric>
     */
    private function legalHoldMetrics(array $counts): array
    {
        return [
            GovernanceMetric::available('Active holds', $this->countOf($counts, LegalHoldStatus::Active->value)),
            GovernanceMetric::available('Released holds', $this->countOf($counts, LegalHoldStatus::Released->value)),
            GovernanceMetric::notSupported(
                'Holds requiring review',
                'legal_holds has no review_date column. Holds are not scheduled for review by this schema.'
            ),
            GovernanceMetric::notSupported(
                'Holds nearing expiry',
                'legal_holds has no expires_at column and LegalHoldStatus has exactly two cases '
                .'(Active, Released). A hold never lapses on its own — it stays active until a governed '
                .'release is performed. Showing an expiry countdown would imply automatic release that '
                .'this domain does not perform.'
            ),
            GovernanceMetric::notSupported(
                'Pending release requests',
                'Release is a single governed transition (LegalHoldService::release()). There is no '
                .'separate release-request record to await approval.'
            ),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, GovernanceMetric>
     */
    private function deletionMetrics(array $counts): array
    {
        return [
            GovernanceMetric::available('Requested', $this->countOf($counts, DeletionRequestStatus::Requested->value)),
            GovernanceMetric::available('Awaiting approval', $this->countOf($counts, DeletionRequestStatus::PendingApproval->value)),
            GovernanceMetric::available('Blocked by legal hold', $this->countOf($counts, DeletionRequestStatus::LegalHoldBlocked->value)),
            GovernanceMetric::available('Blocked by retention', $this->countOf($counts, DeletionRequestStatus::RetentionClearancePending->value)),
            GovernanceMetric::available('Blocked pending export clearance', $this->countOf($counts, DeletionRequestStatus::ExportClearancePending->value)),
            GovernanceMetric::available('Ready for execution', $this->countOf($counts, DeletionRequestStatus::ReadyForExecution->value)),
            GovernanceMetric::available('Denied', $this->countOf($counts, DeletionRequestStatus::Denied->value)),
            GovernanceMetric::available('Cancelled', $this->countOf($counts, DeletionRequestStatus::Cancelled->value)),
            GovernanceMetric::notSupported(
                'In execution / completed',
                'DeletionRequestStatus has no Executed or Completed case. ReadyForExecution is the '
                .'terminal state and DeletionGovernanceService never physically deletes the target row — '
                .'no disposition execution capability exists on this HEAD.'
            ),
            GovernanceMetric::notSupported(
                'Overdue',
                'deletion_requests has no due_date or SLA column. Age is shown per request instead; '
                .'calling a request overdue would require a deadline this domain does not store.'
            ),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, GovernanceMetric>
     */
    private function exportMetrics(array $counts): array
    {
        return [
            GovernanceMetric::available('Requested', $this->countOf($counts, ExportJobStatus::Requested->value)),
            GovernanceMetric::available('In progress', $this->countOf($counts, ExportJobStatus::InProgress->value)),
            GovernanceMetric::available('Completed', $this->countOf($counts, ExportJobStatus::Completed->value)),
            GovernanceMetric::available('Failed', $this->countOf($counts, ExportJobStatus::Failed->value)),
            GovernanceMetric::available('Blocked by export governance policy', $this->countOf($counts, ExportJobStatus::Blocked->value)),
            GovernanceMetric::available('Cancelled', $this->countOf($counts, ExportJobStatus::Cancelled->value)),
            GovernanceMetric::notSupported(
                'Expired downloads',
                'export_jobs stores no file path, size, checksum, encryption state, expiry, or download '
                .'record. A completed export job is a governance milestone, not a downloadable archive — '
                .'there is nothing that can expire.'
            ),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, GovernanceMetric>
     */
    private function importMetrics(array $counts): array
    {
        return [
            GovernanceMetric::available('Draft', $this->countOf($counts, ImportBatchStatus::Draft->value)),
            GovernanceMetric::available('Staged', $this->countOf($counts, ImportBatchStatus::Staged->value)),
            GovernanceMetric::available('Validated', $this->countOf($counts, ImportBatchStatus::Validated->value)),
            GovernanceMetric::available('Preview ready', $this->countOf($counts, ImportBatchStatus::PreviewReady->value)),
            GovernanceMetric::available('Confirmed (awaiting apply)', $this->countOf($counts, ImportBatchStatus::Confirmed->value)),
            GovernanceMetric::available('Applying', $this->countOf($counts, ImportBatchStatus::Applying->value)),
            GovernanceMetric::available('Applied', $this->countOf($counts, ImportBatchStatus::Applied->value)),
            GovernanceMetric::available('Failed', $this->countOf($counts, ImportBatchStatus::Failed->value)),
            GovernanceMetric::available('Rolled back', $this->countOf($counts, ImportBatchStatus::RolledBack->value)),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, GovernanceMetric>
     */
    private function migrationMetrics(array $counts): array
    {
        return [
            GovernanceMetric::available('Draft', $this->countOf($counts, MigrationProjectStatus::Draft->value)),
            GovernanceMetric::available('In progress', $this->countOf($counts, MigrationProjectStatus::InProgress->value)),
            GovernanceMetric::available('Completed', $this->countOf($counts, MigrationProjectStatus::Completed->value)),
            GovernanceMetric::available('Failed', $this->countOf($counts, MigrationProjectStatus::Failed->value)),
            GovernanceMetric::available('Cancelled', $this->countOf($counts, MigrationProjectStatus::Cancelled->value)),
            GovernanceMetric::notSupported(
                'Awaiting cutover',
                'migration_projects has no phase, cutover, or readiness column, and MigrationProjectStatus '
                .'has no cutover case. Cutover is not modelled by this domain.'
            ),
            GovernanceMetric::notSupported(
                'Blocked',
                'migration_projects has no blocked status and no blocker column. A project is Draft, '
                .'InProgress, Completed, Failed, or Cancelled — nothing records a project as held up.'
            ),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, GovernanceMetric>
     */
    private function offboardingMetrics(array $counts): array
    {
        return [
            GovernanceMetric::available('Requested', $this->countOf($counts, OffboardingRequestStatus::Requested->value)),
            GovernanceMetric::available('Export pending', $this->countOf($counts, OffboardingRequestStatus::ExportPending->value)),
            GovernanceMetric::available('Export completed', $this->countOf($counts, OffboardingRequestStatus::ExportCompleted->value)),
            GovernanceMetric::available('Retention clearance pending', $this->countOf($counts, OffboardingRequestStatus::RetentionClearancePending->value)),
            GovernanceMetric::available('Retention cleared', $this->countOf($counts, OffboardingRequestStatus::RetentionCleared->value)),
            GovernanceMetric::available('Blocked by legal hold', $this->countOf($counts, OffboardingRequestStatus::LegalHoldBlocked->value)),
            GovernanceMetric::available('Ready for deletion', $this->countOf($counts, OffboardingRequestStatus::ReadyForDeletion->value)),
            GovernanceMetric::available('Completed', $this->countOf($counts, OffboardingRequestStatus::Completed->value)),
            GovernanceMetric::available('Cancelled', $this->countOf($counts, OffboardingRequestStatus::Cancelled->value)),
        ];
    }

    // -----------------------------------------------------------------
    // Requires Attention
    // -----------------------------------------------------------------

    /**
     * Only conditions that were genuinely evaluated appear here. The
     * two structural gaps below are reported every time precisely
     * because they cannot be evaluated away — they are properties of
     * the current schema, not transient states.
     *
     * @param  array<string, array<string, int>>  $counts
     * @return array<int, GovernanceAttentionItem>
     */
    private function attentionItems(array $counts): array
    {
        $items = [];

        $holdBlockedDeletions = $this->countOf($counts['deletion_requests'], DeletionRequestStatus::LegalHoldBlocked->value);
        if ($holdBlockedDeletions > 0) {
            $items[] = GovernanceAttentionItem::blocker(
                'Deletion requests blocked by legal hold',
                'These requests cannot proceed until the applicable hold is released through the governed release path.',
                $holdBlockedDeletions,
                '/admin/deletion-requests',
            );
        }

        $holdBlockedOffboarding = $this->countOf($counts['offboarding_requests'], OffboardingRequestStatus::LegalHoldBlocked->value);
        if ($holdBlockedOffboarding > 0) {
            $items[] = GovernanceAttentionItem::blocker(
                'Offboarding blocked by legal hold',
                'Offboarding cannot complete for these firms while an active firm-scope hold applies.',
                $holdBlockedOffboarding,
                '/admin/offboarding-requests',
            );
        }

        $failedExports = $this->countOf($counts['export_jobs'], ExportJobStatus::Failed->value);
        if ($failedExports > 0) {
            $items[] = GovernanceAttentionItem::warning(
                'Export jobs failed',
                'Failed export jobs may be holding up an offboarding or deletion clearance chain.',
                $failedExports,
                '/admin/export-jobs',
            );
        }

        $blockedExports = $this->countOf($counts['export_jobs'], ExportJobStatus::Blocked->value);
        if ($blockedExports > 0) {
            $items[] = GovernanceAttentionItem::warning(
                'Export jobs blocked by export governance policy',
                'ExportGovernancePolicyService refused these requests. Review the recorded reason on each job.',
                $blockedExports,
                '/admin/export-jobs',
            );
        }

        $failedImports = $this->countOf($counts['import_batches'], ImportBatchStatus::Failed->value);
        if ($failedImports > 0) {
            $items[] = GovernanceAttentionItem::warning(
                'Import batches failed',
                'Review the failed rows and errors recorded against each batch before retrying.',
                $failedImports,
                '/admin/import-batches',
            );
        }

        $failedMigrations = $this->countOf($counts['migration_projects'], MigrationProjectStatus::Failed->value);
        if ($failedMigrations > 0) {
            $items[] = GovernanceAttentionItem::warning(
                'Data migration projects failed',
                'These are customer/tenant data migration projects, not deployment fleet migrations.',
                $failedMigrations,
                '/admin/migration-projects',
            );
        }

        // Structural gaps. These are deliberately always reported: they
        // are properties of the current schema, and §30 of the mission
        // brief requires unresolved hold coverage to become either
        // VERIFIED or a visible blocker rather than a passive banner.
        $unresolved = $this->retentionRegistry->categoriesWithUnresolvedLegalHoldCoverage();
        if ($unresolved !== []) {
            $items[] = GovernanceAttentionItem::blocker(
                'Retention categories with unresolved legal hold coverage',
                'These categories are swept by RetentionSweepJob, which does not consult '
                .'LegalHoldService::checkHold() — no resolution layer maps a swept row back to a '
                .'client_id/matter_id a hold check could use. Affected categories: '
                .implode(', ', $unresolved).'. Closing this needs a backend resolution layer, not a UI change.',
                count($unresolved),
                '/admin/retention',
            );
        }

        $items[] = GovernanceAttentionItem::unevaluated(
            'Retention sweep execution history',
            'Cannot be evaluated. Sweep evidence is written to '
            .'storage/logs/integration-retention-sweep.log as flat log lines only — there is no '
            .'sweep-history table and no scheduler-run table, so whether a sweep has ever run, '
            .'succeeded, or failed cannot be determined from queryable data. Scheduled cadence: '
            .self::SWEEP_SCHEDULE_DESCRIPTION.' (configuration only).'
        );

        $items[] = GovernanceAttentionItem::unevaluated(
            'Retention policy row level security',
            'Cannot be evaluated as safe. retention_policies carries no RLS at all (neither ENABLE nor '
            .'FORCE) despite being classified Hybrid — firm_id null is a platform default, firm_id set '
            .'is a firm override. This is an inherited coverage gap. Changing it requires an '
            .'owner-approved schema/policy change and is deliberately not performed here.'
        );

        return $items;
    }
}
