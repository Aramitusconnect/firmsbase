<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Firm;
use App\Models\FirmPracticeArea;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaDependencyAnalysisService;
use App\Services\Configuration\PracticeAreaMergeProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Two guarantees this suite exists to lock down:
 *
 * 1. THE MISLEADING ZERO (mission sections 24/77). Tenant-owned
 *    dependency tables are FORCE-RLS protected; counting them from a
 *    platform-admin session silently returns 0 rather than failing.
 *    The impact preview must therefore never report an unscanned
 *    tenant dependency as a number — it must report it as unavailable.
 *    The decisive test below creates a REAL firm_practice_areas row and
 *    proves the unscanned path refuses to claim zero while the scanned
 *    path finds it.
 *
 * 2. NO MERGE EXECUTION (mission sections 36/96). Merge analysis is
 *    allowed; execution is not, regardless of evidence strength.
 */
class PracticeAreaDependencyAndMergeProposalTest extends TestCase
{
    use RefreshDatabase;

    private PracticeAreaDependencyAnalysisService $dependencies;

    private PracticeAreaMergeProposalService $proposals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dependencies = app(PracticeAreaDependencyAnalysisService::class);
        $this->proposals = app(PracticeAreaMergeProposalService::class);
    }

    public function test_global_dependencies_are_counted_exactly(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_dep_global', 'name' => 'Zzz Dep Global']);
        MatterType::factory()->count(3)->forPracticeArea($practiceArea)->create();

        $matterTypes = collect($this->dependencies->globalDependencies($practiceArea))
            ->firstWhere('label', 'Matter types');

        $this->assertSame(3, $matterTypes['count']);
        $this->assertTrue($matterTypes['available']);
        $this->assertSame('global', $matterTypes['scope']);
    }

    public function test_a_practice_area_with_no_global_references_reports_a_real_measured_zero(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_dep_none', 'name' => 'Zzz Dep None']);

        foreach ($this->dependencies->globalDependencies($practiceArea) as $row) {
            $this->assertSame(0, $row['count']);
            // A measured zero is still AVAILABLE data — the distinction
            // mission section 24 turns on.
            $this->assertTrue($row['available']);
        }
    }

    /**
     * The core anti-fabrication guarantee.
     */
    public function test_tenant_scoped_dependencies_are_never_reported_as_zero_when_unscanned(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_dep_tenant', 'name' => 'Zzz Dep Tenant']);
        $firm = Firm::factory()->create();

        // A REAL tenant-owned reference exists...
        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($practiceArea)->create();

        // ...but the unscanned view must not claim to know the count.
        foreach ($this->dependencies->tenantDependenciesUnscanned() as $row) {
            $this->assertNull($row['count'], "{$row['label']} must not report a count when unscanned");
            $this->assertFalse($row['available']);
            $this->assertNotEmpty($row['reason']);
        }
    }

    public function test_scanning_finds_the_real_tenant_owned_reference(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_dep_scan', 'name' => 'Zzz Dep Scan']);
        $firm = Firm::factory()->create();

        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($practiceArea)->create();

        $scan = $this->dependencies->tenantDependenciesScanned($practiceArea);

        $enabled = collect($scan['rows'])->firstWhere('label', 'Firms with this practice area enabled');

        $this->assertSame(1, $enabled['count'], 'the per-firm context scan must see the row RLS hides from a platform session');
        $this->assertTrue($enabled['available']);
        $this->assertSame(1, $scan['firmsAffected']);
    }

    public function test_scan_reports_how_many_firms_it_actually_covered(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_dep_cover', 'name' => 'Zzz Dep Cover']);
        Firm::factory()->count(2)->create();

        $scan = $this->dependencies->tenantDependenciesScanned($practiceArea);

        $this->assertSame(Firm::query()->count(), $scan['firmsTotal']);
        $this->assertSame($scan['firmsTotal'], $scan['firmsScanned']);
        $this->assertFalse($scan['capped'], 'a catalog smaller than the cap must not be reported as capped');
    }

    public function test_has_global_references_is_true_only_when_a_global_row_exists(): void
    {
        $unused = PracticeArea::factory()->create(['code' => 'zzz_ref_no', 'name' => 'Zzz Ref No']);
        $used = PracticeArea::factory()->create(['code' => 'zzz_ref_yes', 'name' => 'Zzz Ref Yes']);
        MatterType::factory()->forPracticeArea($used)->create();

        $this->assertFalse($this->dependencies->hasGlobalReferences($unused));
        $this->assertTrue($this->dependencies->hasGlobalReferences($used));
    }

    public function test_merge_proposal_reports_evidence_without_claiming_semantic_identity(): void
    {
        $source = PracticeArea::factory()->create(['code' => 'zzz_merge_src', 'name' => 'Zzz Merge Case']);
        $target = PracticeArea::factory()->create(['code' => 'zzz-merge-case', 'name' => 'Zzz merge case']);

        $proposal = $this->proposals->buildProposal($source, $target);

        $this->assertSame('SUSPECTED_DUPLICATE', $proposal['evidence_strength']);
        $this->assertNotEmpty($proposal['duplicate_evidence']);

        // Mission section 35: never infer equivalence from names alone.
        $this->assertSame('UNCERTAIN', $proposal['semantically_identical']);
        $this->assertTrue($proposal['owner_approval_required']);
        $this->assertFalse($proposal['executed']);
        $this->assertFalse($proposal['merge_safe']);
    }

    public function test_merge_proposal_defaults_to_unscanned_tenant_dependencies(): void
    {
        $source = PracticeArea::factory()->create(['code' => 'zzz_ms_a', 'name' => 'Zzz Ms A']);
        $target = PracticeArea::factory()->create(['code' => 'zzz_ms_b', 'name' => 'Zzz Ms B']);

        $proposal = $this->proposals->buildProposal($source, $target);

        $this->assertFalse($proposal['dependencies']['source']['tenant_scanned']);

        foreach ($proposal['dependencies']['source']['tenant'] as $row) {
            $this->assertNull($row['count']);
        }
    }

    public function test_merge_proposal_can_include_a_scanned_tenant_impact(): void
    {
        $source = PracticeArea::factory()->create(['code' => 'zzz_msc_a', 'name' => 'Zzz Msc A']);
        $target = PracticeArea::factory()->create(['code' => 'zzz_msc_b', 'name' => 'Zzz Msc B']);
        $firm = Firm::factory()->create();

        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($source)->create();

        $proposal = $this->proposals->buildProposal($source, $target, scanTenantScoped: true);

        $this->assertTrue($proposal['dependencies']['source']['tenant_scanned']);
        $this->assertSame(1, $proposal['dependencies']['source']['firms_affected']);
    }

    public function test_unrelated_pair_reports_insufficient_evidence_rather_than_a_duplicate_claim(): void
    {
        $source = PracticeArea::factory()->create(['code' => 'zzz_alpha_law', 'name' => 'Zzz Alpha Law']);
        $target = PracticeArea::factory()->create(['code' => 'zzz_beta_law', 'name' => 'Zzz Beta Law']);

        $proposal = $this->proposals->buildProposal($source, $target);

        $this->assertSame('INSUFFICIENT_EVIDENCE', $proposal['evidence_strength']);
        $this->assertSame([], $proposal['duplicate_evidence']);
    }

    public function test_a_practice_area_cannot_be_merged_into_itself(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_self', 'name' => 'Zzz Self']);

        $this->expectException(RuntimeException::class);

        $this->proposals->buildProposal($practiceArea, $practiceArea);
    }

    /**
     * Mission sections 36/96: execution is unavailable, unconditionally.
     */
    public function test_merge_execution_is_not_available_from_the_proposal_service(): void
    {
        $this->assertFalse(
            method_exists($this->proposals, 'execute'),
            'no merge execution path may exist without separate owner approval',
        );
        $this->assertFalse(method_exists($this->proposals, 'merge'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owner approval/i');

        $this->proposals->assertMergeExecutionNotPermitted();
    }

    /**
     * Building a proposal must leave the catalog byte-for-byte
     * untouched — analysis never mutates.
     */
    public function test_building_a_proposal_mutates_nothing(): void
    {
        $source = PracticeArea::factory()->create(['code' => 'zzz_imm_a', 'name' => 'Zzz Imm A']);
        $target = PracticeArea::factory()->create(['code' => 'zzz_imm_b', 'name' => 'Zzz Imm B']);

        $before = PracticeArea::query()->orderBy('id')->get()->toJson();

        $this->proposals->buildProposal($source, $target, scanTenantScoped: true);

        $this->assertSame($before, PracticeArea::query()->orderBy('id')->get()->toJson());
    }
}
