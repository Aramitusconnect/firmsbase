<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Directory;

use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryAttorneyFirm;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\FirmOffice;
use App\Models\Firm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DirectoryDomainFoundationTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 1. Proves the core schema foundation
 * (DirectoryFirm, FirmOffice, DirectoryAttorney,
 * DirectoryAttorneyFirm): factories/relationships work, slug
 * generation + collision handling works (section 44), the derived
 * profile-level accessor works (section 15), and — critically — every
 * new table is genuinely exempt from RLS (no relrowsecurity), never
 * scoped by ambient tenant context the way a firm-owned table would
 * be, since this is platform-global marketplace data (section 59).
 */
class DirectoryDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKETPLACE_TABLES = ['directory_firms', 'firm_offices', 'directory_attorneys', 'directory_attorney_firm'];

    public function test_directory_firm_can_be_created_with_offices_and_attorneys(): void
    {
        $directoryFirm = DirectoryFirm::factory()->create();
        $office = FirmOffice::factory()->forFirm($directoryFirm)->create();
        $attorney = DirectoryAttorney::factory()->create();
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $directoryFirm)->create(['firm_office_id' => $office->id]);

        $this->assertTrue($directoryFirm->offices->contains($office));
        $this->assertCount(1, $directoryFirm->attorneyRelationships);
        $this->assertTrue($attorney->firmRelationships->first()->firm->is($directoryFirm));
        $this->assertTrue($attorney->firmRelationships->first()->office->is($office));
    }

    public function test_directory_firm_nullable_tenant_link_defaults_to_null(): void
    {
        $directoryFirm = DirectoryFirm::factory()->create();

        $this->assertNull($directoryFirm->firm_id);
        $this->assertNull($directoryFirm->firm);
    }

    public function test_directory_firm_can_optionally_link_to_a_real_tenant_firm(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->create(['firm_id' => $firm->id]);

        $this->assertTrue($directoryFirm->firm->is($firm));
    }

    public function test_deleting_the_linked_tenant_firm_does_not_delete_the_directory_listing(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->create(['firm_id' => $firm->id]);

        $firm->delete();

        $this->assertNotNull(DirectoryFirm::find($directoryFirm->id));
        $this->assertNull($directoryFirm->fresh()->firm_id);
    }

    public function test_slug_is_unique_and_collisions_are_suffixed_not_overwritten(): void
    {
        $first = DirectoryFirm::factory()->create(['display_name' => 'Smith Law', 'legal_name' => 'Smith Law PLLC', 'name_normalized' => 'smith law', 'slug' => DirectoryFirm::generateUniqueSlug('Smith Law')]);
        $second = DirectoryFirm::factory()->create(['display_name' => 'Smith Law', 'legal_name' => 'Smith Law PLLC', 'name_normalized' => 'smith law', 'slug' => DirectoryFirm::generateUniqueSlug('Smith Law')]);

        $this->assertSame('smith-law', $first->slug);
        $this->assertSame('smith-law-2', $second->slug);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_profile_level_is_derived_never_a_stored_independent_flag(): void
    {
        $unclaimed = DirectoryFirm::factory()->unclaimed()->create();
        $claimed = DirectoryFirm::factory()->claimed()->create();
        $member = DirectoryFirm::factory()->member()->create();

        $this->assertSame(DirectoryFirmProfileLevel::PublicListing, $unclaimed->profileLevel());
        $this->assertSame(DirectoryFirmProfileLevel::ClaimedProfile, $claimed->profileLevel());
        $this->assertSame(DirectoryFirmProfileLevel::VerifiedMember, $member->profileLevel());
    }

    public function test_a_member_firm_is_always_also_claimed_by_construction_of_the_factory_state(): void
    {
        $member = DirectoryFirm::factory()->member()->create();

        $this->assertTrue($member->is_claimed);
        $this->assertTrue($member->is_marketplace_member);
    }

    public function test_publication_state_controls_public_visibility(): void
    {
        $published = DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Published]);
        $draft = DirectoryFirm::factory()->draft()->create();
        $suspended = DirectoryFirm::factory()->suspended()->create();

        $this->assertTrue($published->isPubliclyVisible());
        $this->assertFalse($draft->isPubliclyVisible());
        $this->assertFalse($suspended->isPubliclyVisible());
    }

    public function test_attorney_firm_relationship_state_controls_public_displayability(): void
    {
        $current = DirectoryAttorneyFirm::factory()->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Current]);
        $disputed = DirectoryAttorneyFirm::factory()->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Disputed]);

        $this->assertTrue($current->relationship_state->isPubliclyDisplayable());
        $this->assertFalse($disputed->relationship_state->isPubliclyDisplayable());
    }

    public function test_an_attorney_moving_firms_transitions_the_existing_relationship_row_not_a_duplicate(): void
    {
        $attorney = DirectoryAttorney::factory()->create();
        $firm = DirectoryFirm::factory()->create();
        $relationship = DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $firm)->create();

        $relationship->update(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Former, 'ended_at' => now()]);

        $this->assertSame(1, DirectoryAttorneyFirm::query()->where('directory_attorney_id', $attorney->id)->where('directory_firm_id', $firm->id)->count());
        $this->assertSame(DirectoryAttorneyFirmRelationshipState::Former, $relationship->fresh()->relationship_state);
    }

    public function test_duplicate_attorney_firm_relationship_row_is_rejected_by_the_unique_constraint(): void
    {
        $attorney = DirectoryAttorney::factory()->create();
        $firm = DirectoryFirm::factory()->create();
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $firm)->create();

        $this->expectException(QueryException::class);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $firm)->create();
    }

    /**
     * Section 59: marketplace directory tables are platform-global,
     * never firm-tenant RLS data. Every one of the four new tables
     * must be genuinely exempt — no row-level security applied at all
     * — matching RowLevelSecurityCoverageMappingService::exemptTables().
     */
    public function test_every_marketplace_directory_table_is_genuinely_exempt_from_row_level_security(): void
    {
        foreach (self::MARKETPLACE_TABLES as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relrowsecurity, "RLS must NOT be enabled on marketplace directory table {$table} — it is platform-global data.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "FORCE RLS must NOT be enabled on marketplace directory table {$table}.");
        }
    }

    public function test_marketplace_directory_tables_are_readable_with_no_ambient_tenant_context(): void
    {
        DirectoryFirm::factory()->create();

        $this->assertNoDatabaseTenantContext();
        $this->assertGreaterThan(0, DirectoryFirm::query()->count());
    }
}
