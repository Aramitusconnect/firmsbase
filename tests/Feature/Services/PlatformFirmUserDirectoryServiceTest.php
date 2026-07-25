<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmUserDirectoryService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformFirmUserDirectoryServiceTest — Phase 1 FirmsVault Admin
 * Control Center. Proves the per-firm loop + merge read pattern this
 * service uses to work around firm_users' FORCE RLS (see the service's
 * own docblock): every firm's rows are visible in listAll() (proving
 * the loop genuinely covers every firm, not just the first/current
 * context), findByUuid() correctly resolves a row scoped to the RIGHT
 * firm and returns null for the WRONG firm (proving no cross-tenant
 * leakage through this read path), and access is gated by
 * canAccessPlatformAdministration() exactly like the Policy layer.
 */
class PlatformFirmUserDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformFirmUserDirectoryService $service;

    private PlatformRoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleService = new PlatformRoleService;
        $this->service = app(PlatformFirmUserDirectoryService::class);
    }

    private function adminWithAccess(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    public function test_list_all_merges_rows_across_every_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        $this->createWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());
        $this->createWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());
        $this->createWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());

        $admin = $this->adminWithAccess();

        $rows = $this->service->listAll($admin);

        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->where('firm_name', 'Firm A'));
        $this->assertCount(2, $rows->where('firm_name', 'Firm B'));
    }

    public function test_list_all_can_be_narrowed_to_a_single_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        $this->createWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());
        $this->createWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());

        $admin = $this->adminWithAccess();

        $rows = $this->service->listAll($admin, onlyFirmId: $firmA->id);

        $this->assertCount(1, $rows);
        $this->assertSame('Firm A', $rows->first()['firm_name']);
    }

    public function test_find_by_uuid_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $firmUserA = $this->createWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());

        $admin = $this->adminWithAccess();

        $found = $this->service->findByUuid($admin, $firmA, $firmUserA->uuid);
        $this->assertNotNull($found);
        $this->assertSame($firmUserA->id, $found->id);

        $notFoundUnderWrongFirm = $this->service->findByUuid($admin, $firmB, $firmUserA->uuid);
        $this->assertNull(
            $notFoundUnderWrongFirm,
            'A firm_user belonging to firm A must never resolve when looked up under firm B\'s context — this is the no-cross-tenant-leakage guarantee.'
        );
    }

    public function test_a_role_without_platform_administration_access_is_denied(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SalesRep);

        $this->expectException(RuntimeException::class);

        $this->service->listAll($admin);
    }
}
