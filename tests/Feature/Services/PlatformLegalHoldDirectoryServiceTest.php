<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Services\PlatformLegalHoldDirectoryService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformLegalHoldDirectoryServiceTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Legal Holds module.
 */
final class PlatformLegalHoldDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformLegalHoldDirectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlatformLegalHoldDirectoryService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_list_merges_holds_across_every_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        LegalHold::factory()->forFirm($firmA)->create();
        LegalHold::factory()->forFirm($firmB)->create();
        LegalHold::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin);

        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->where('firm_name', 'Firm A'));
        $this->assertCount(2, $rows->where('firm_name', 'Firm B'));
    }

    public function test_firm_filter_narrows_to_a_single_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        LegalHold::factory()->forFirm($firmA)->create();
        LegalHold::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin, ['firm_uuid' => $firmA->uuid]);

        $this->assertCount(1, $rows);
        $this->assertSame('Firm A', $rows->first()['firm_name']);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        LegalHold::factory()->forFirm($firm)->create();
        LegalHold::factory()->forFirm($firm)->released()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin, ['status' => LegalHoldStatus::Released->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(LegalHoldStatus::Released->value, $rows->first()['status']);
    }

    public function test_scope_type_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        LegalHold::factory()->forFirm($firm)->create(['scope_type' => LegalHoldScope::Firm]);
        LegalHold::factory()->forFirm($firm)->create(['scope_type' => LegalHoldScope::Matter, 'matter_id' => $matter->id]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin, ['scope_type' => LegalHoldScope::Matter->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(LegalHoldScope::Matter->value, $rows->first()['scope_type']);
    }

    public function test_orders_deterministically_by_id_when_placed_at_ties(): void
    {
        $firm = Firm::factory()->create();
        $tie = now();

        $holds = collect(range(1, 4))->map(fn () => LegalHold::factory()->forFirm($firm)->create(['placed_at' => $tie]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $firstPass = $this->service->list($admin)->pluck('id')->all();
        $secondPass = $this->service->list($admin)->pluck('id')->all();

        $this->assertSame($firstPass, $secondPass);
        $this->assertSame($holds->sortByDesc('id')->pluck('id')->values()->all(), $firstPass);
    }

    public function test_find_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $hold = LegalHold::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $found = $this->service->find($admin, $firmA, $hold->id);
        $this->assertNotNull($found);
        $this->assertSame($hold->id, $found['id']);

        $notFoundUnderWrongFirm = $this->service->find($admin, $firmB, $hold->id);
        $this->assertNull($notFoundUnderWrongFirm);
    }

    public function test_a_role_without_governance_access_is_denied(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->expectException(RuntimeException::class);

        $this->service->list($admin);
    }
}
