<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Models\Firm;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * PracticeAreaDependencyAnalysisService — read-only. Answers "what
 * actually references this practice area?" for the deactivation impact
 * preview (section 34) and the merge proposal (sections 35–37).
 *
 * THE MISLEADING-ZERO PROBLEM THIS CLASS EXISTS TO SOLVE:
 * of the nine tables carrying a `practice_area_id`, four are
 * tenant-owned and protected by FORCE ROW LEVEL SECURITY — `matters`,
 * `firm_practice_areas`, `matter_budget_templates` and
 * `marketplace_intakes`. A plain `count()` against any of them from a
 * platform-admin (zero-tenant-context) session does not fail: it
 * silently returns 0, because RLS filters every row out. Rendering
 * that 0 as "0 matters use this practice area" would be a fabricated
 * safety signal on the exact screen an operator uses to decide whether
 * deactivating or merging taxonomy is safe — precisely the failure
 * mission section 24 ("a measured zero is not the same as unavailable
 * data") and section 77 ("if impact is unavailable: Not Available; do
 * not invent estimates") forbid.
 *
 * So the two classes of dependency are kept strictly apart:
 *
 *   GLOBAL dependencies (`matter_types`, `template_packs`,
 *   `intake_templates`, and the two marketplace directory pivots) carry
 *   no firm_id and no RLS. One aggregate query each; always exact.
 *
 *   TENANT-SCOPED dependencies are NEVER counted from a zero-context
 *   session. They are counted only when the operator explicitly asks
 *   for a full impact preview, via an approved per-firm
 *   runWithFirmContext() loop (the same pattern
 *   PlatformFirmUserDirectoryService and
 *   PlatformEntitlementOverrideDirectoryService already use for
 *   cross-firm reads), and the result is always tagged with how many
 *   firms were actually scanned. Until then they report
 *   available=false with an explicit reason, never a number.
 *
 * The per-firm loop is O(number of firms) and is therefore deliberately
 * NOT wired into any list page or dashboard metric (section 91) — it
 * runs only on a single record, only on demand, and only behind an
 * explicit firm scan cap.
 */
class PracticeAreaDependencyAnalysisService
{
    /**
     * Upper bound on how many firms one on-demand impact preview will
     * scan. A preview that silently stopped early would understate
     * impact, so exceeding this is reported as `capped`, never hidden
     * (section 91's "no silent caps").
     */
    public const FIRM_SCAN_CAP = 250;

    /**
     * Global, non-tenant tables keyed by operator-facing label. Each
     * entry is [table, column] and is counted with one aggregate query.
     */
    private const GLOBAL_DEPENDENCIES = [
        'Matter types' => ['matter_types', 'practice_area_id'],
        'Template packs' => ['template_packs', 'practice_area_id'],
        'Intake templates' => ['intake_templates', 'practice_area_id'],
        'Marketplace firm listings' => ['directory_firm_practice_areas', 'practice_area_id'],
        'Marketplace attorney listings' => ['directory_attorney_practice_areas', 'practice_area_id'],
    ];

    /**
     * Tenant-owned, FORCE-RLS tables. Counted only inside a per-firm
     * context loop — never from a platform session.
     *
     * `matters` links to a practice area by `primary_practice_area_id`
     * (verified against the live schema — the column is NOT called
     * `practice_area_id`, which is why it is spelled out here rather
     * than assumed). Matters can ALSO depend on a practice area
     * indirectly, through `matter_type_id` → `matter_types
     * .practice_area_id`; that second path is a genuinely different
     * dependency and is counted separately below rather than folded
     * into this one, so a merge/deactivation preview never understates
     * matter impact by looking at only one of the two links.
     */
    private const TENANT_DEPENDENCIES = [
        'Firms with this practice area enabled' => ['firm_practice_areas', 'practice_area_id'],
        'Matters (primary practice area)' => ['matters', 'primary_practice_area_id'],
        'Matter budget templates' => ['matter_budget_templates', 'practice_area_id'],
        'Marketplace intakes' => ['marketplace_intakes', 'practice_area_id'],
    ];

    /**
     * Label for the indirect matters→matter_types→practice_area path.
     */
    private const MATTERS_VIA_MATTER_TYPE_LABEL = 'Matters (via this practice area\'s matter types)';

    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * Exact counts for every dependency that carries no tenant scope.
     * Safe to call from any platform-admin session.
     *
     * @return list<array{label: string, count: int, available: true, scope: 'global'}>
     */
    public function globalDependencies(PracticeArea $practiceArea): array
    {
        $rows = [];

        foreach (self::GLOBAL_DEPENDENCIES as $label => [$table, $column]) {
            $rows[] = [
                'label' => $label,
                'count' => DB::table($table)->where($column, $practiceArea->id)->count(),
                'available' => true,
                'scope' => 'global',
            ];
        }

        return $rows;
    }

    /**
     * Tenant-scoped dependencies WITHOUT scanning — every entry is
     * reported as unavailable, with the reason. This is what list and
     * detail pages use by default.
     *
     * @return list<array{label: string, count: null, available: false, scope: 'tenant', reason: string}>
     */
    public function tenantDependenciesUnscanned(): array
    {
        $rows = [];

        $labels = array_merge(array_keys(self::TENANT_DEPENDENCIES), [self::MATTERS_VIA_MATTER_TYPE_LABEL]);

        foreach ($labels as $label) {
            $rows[] = [
                'label' => $label,
                'count' => null,
                'available' => false,
                'scope' => 'tenant',
                'reason' => 'Tenant-scoped (FORCE RLS) — run the full impact preview to count this across firms.',
            ];
        }

        return $rows;
    }

    /**
     * Tenant-scoped dependency counts, aggregated across firms via the
     * approved per-firm context loop. On-demand only.
     *
     * @return array{rows: list<array{label: string, count: int, available: true, scope: 'tenant'}>, firmsScanned: int, firmsTotal: int, capped: bool, firmsAffected: int}
     */
    public function tenantDependenciesScanned(PracticeArea $practiceArea): array
    {
        $firmsTotal = Firm::query()->count();

        // Only the ids are selected, and the id (never a partially
        // selected Firm model) is what is handed to
        // runWithFirmContext() — TenantContextResolver rejects a Firm
        // instance whose full row was not loaded, and loading full rows
        // for up to FIRM_SCAN_CAP firms would be wasted work here since
        // nothing below reads any other Firm column.
        $firmIds = Firm::query()
            ->orderBy('id')
            ->limit(self::FIRM_SCAN_CAP)
            ->pluck('id');

        // matter_types is a GLOBAL table, so this id set is resolvable
        // once, outside the per-firm loop, rather than re-queried per firm.
        $matterTypeIds = DB::table('matter_types')
            ->where('practice_area_id', $practiceArea->id)
            ->pluck('id')
            ->all();

        $totals = array_fill_keys(
            array_merge(array_keys(self::TENANT_DEPENDENCIES), [self::MATTERS_VIA_MATTER_TYPE_LABEL]),
            0,
        );
        $firmsAffected = 0;

        foreach ($firmIds as $firmId) {
            $perFirm = $this->tenantContext->runWithFirmContext($firmId, function () use ($practiceArea, $matterTypeIds): array {
                $counts = [];

                foreach (self::TENANT_DEPENDENCIES as $label => [$table, $column]) {
                    $counts[$label] = DB::table($table)->where($column, $practiceArea->id)->count();
                }

                $counts[self::MATTERS_VIA_MATTER_TYPE_LABEL] = $matterTypeIds === []
                    ? 0
                    : DB::table('matters')->whereIn('matter_type_id', $matterTypeIds)->count();

                return $counts;
            });

            $firmTouched = false;

            foreach ($perFirm as $label => $count) {
                $totals[$label] += $count;

                if ($count > 0) {
                    $firmTouched = true;
                }
            }

            if ($firmTouched) {
                $firmsAffected++;
            }
        }

        $rows = [];

        foreach ($totals as $label => $count) {
            $rows[] = [
                'label' => $label,
                'count' => $count,
                'available' => true,
                'scope' => 'tenant',
            ];
        }

        return [
            'rows' => $rows,
            'firmsScanned' => $firmIds->count(),
            'firmsTotal' => $firmsTotal,
            'capped' => $firmsTotal > $firmIds->count(),
            'firmsAffected' => $firmsAffected,
        ];
    }

    /**
     * True when anything at all references this practice area through a
     * GLOBAL table. Used to decide whether the canonical `code` may
     * still be edited freely (section 33) — deliberately conservative:
     * it answers only from tables that can be read exactly, and a
     * practice area with no global references may still be referenced
     * by tenant-owned rows, which is why isReferenced() is never
     * treated as proof that a code change is safe, only that it is
     * definitely NOT safe when true.
     */
    public function hasGlobalReferences(PracticeArea $practiceArea): bool
    {
        foreach (self::GLOBAL_DEPENDENCIES as [$table, $column]) {
            if (DB::table($table)->where($column, $practiceArea->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
