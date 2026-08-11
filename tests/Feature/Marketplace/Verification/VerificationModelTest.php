<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Verification;

use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Services\MarketplaceBadgeService;
use App\Marketplace\Services\MarketplaceVerificationService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VerificationModelTest — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 7. Section 24: multi-dimensional verification, never one
 * Boolean, each dimension carrying its own state/verified-at/verified-
 * by/source/expiration/revocation/audit.
 */
final class VerificationModelTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MarketplaceVerificationService::class);
    }

    public function test_verifying_a_dimension_creates_a_verified_record_with_full_fields(): void
    {
        $firm = DirectoryFirm::factory()->claimed()->create();
        $tenantFirm = Firm::factory()->create();
        $firm->update(['firm_id' => $tenantFirm->id]);
        $admin = PlatformAdmin::factory()->create();

        $verification = $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview, now()->addYear(), 'Reviewed articles of incorporation.');

        $this->assertSame(VerificationState::Verified, $verification->state);
        $this->assertSame(VerificationSource::AdminDocumentReview, $verification->source);
        $this->assertNotNull($verification->verified_at);
        $this->assertSame($admin->id, $verification->verified_by_platform_admin_id);
        $this->assertNotNull($verification->expires_at);
        $this->assertSame('Reviewed articles of incorporation.', $verification->notes);
        $this->assertTrue($verification->isCurrentlyVerified());
    }

    public function test_dimensions_are_independent_verifying_one_does_not_verify_another(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);

        $this->assertTrue($this->service->isVerified($firm, VerificationDimension::FirmAuthority));
        $this->assertFalse($this->service->isVerified($firm, VerificationDimension::DomainEmail));
        $this->assertFalse($this->service->isVerified($firm, VerificationDimension::Membership));
    }

    public function test_a_second_verify_call_on_the_same_dimension_updates_in_place_never_duplicates(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);
        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::ThirdPartyBarRecord);

        $this->assertSame(1, DirectoryVerification::query()
            ->where('verifiable_type', DirectoryFirm::class)
            ->where('verifiable_id', $firm->id)
            ->where('dimension', VerificationDimension::FirmAuthority->value)
            ->count());

        $current = $this->service->statusFor($firm, VerificationDimension::FirmAuthority);
        $this->assertSame(VerificationSource::ThirdPartyBarRecord, $current->source);
    }

    public function test_revoking_a_verified_dimension_transitions_state_and_clears_verified_status(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();
        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);

        $revoked = $this->service->revoke($firm, VerificationDimension::FirmAuthority, $admin, 'Evidence could not be re-confirmed.');

        $this->assertSame(VerificationState::Revoked, $revoked->state);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('Evidence could not be re-confirmed.', $revoked->revocation_reason);
        $this->assertFalse($this->service->isVerified($firm, VerificationDimension::FirmAuthority));
    }

    public function test_revoking_a_dimension_that_was_never_verified_is_rejected(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->revoke($firm, VerificationDimension::FirmAuthority, $admin, 'n/a');
    }

    public function test_revoking_an_already_revoked_dimension_is_rejected(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();
        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);
        $this->service->revoke($firm, VerificationDimension::FirmAuthority, $admin, 'first revocation');

        $this->expectException(\RuntimeException::class);
        $this->service->revoke($firm, VerificationDimension::FirmAuthority, $admin, 'second revocation');
    }

    public function test_expire_stale_transitions_only_past_due_verified_records(): void
    {
        $staleFirm = DirectoryFirm::factory()->unclaimed()->create();
        $freshFirm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();

        $stale = $this->service->verify($staleFirm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview, now()->subDay());
        $fresh = $this->service->verify($freshFirm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview, now()->addYear());

        $expiredCount = $this->service->expireStale();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(VerificationState::Expired, $stale->fresh()->state);
        $this->assertSame(VerificationState::Verified, $fresh->fresh()->state);
        $this->assertFalse($this->service->isVerified($staleFirm, VerificationDimension::FirmAuthority));
        $this->assertTrue($this->service->isVerified($freshFirm, VerificationDimension::FirmAuthority));
    }

    public function test_a_verified_record_with_no_expiration_never_expires(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();
        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);

        $this->service->expireStale();

        $this->assertTrue($this->service->isVerified($firm, VerificationDimension::FirmAuthority));
    }

    // ------------------------------------------------------------
    // Audit routing — linked tenant firm vs. genuinely unclaimed.
    // ------------------------------------------------------------

    public function test_verifying_a_claimed_firms_dimension_records_an_audit_event_scoped_to_its_linked_tenant_firm(): void
    {
        $tenantFirm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $tenantFirm->id]);
        $admin = PlatformAdmin::factory()->create();

        $this->service->verify($directoryFirm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);

        $event = $this->runWithFirmContext($tenantFirm, fn () => SecurityEvent::query()
            ->where('event_type', 'marketplace_verification_verified')
            ->where('firm_id', $tenantFirm->id)
            ->first());

        $this->assertNotNull($event, 'Verifying a claimed listing must record a security_events row scoped to its linked tenant firm.');
    }

    public function test_verifying_an_unclaimed_firms_dimension_records_a_null_firm_audit_event(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->service->verify($directoryFirm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);

        $event = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->whereNull('firm_id')
            ->where('event_type', 'marketplace_verification_verified')
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($event, 'Verifying an unclaimed listing must still record an audit event, with a null firm_id, never silently skipped.');
        $this->assertSame($admin->id, $event->actor_id);
    }

    // ------------------------------------------------------------
    // Badge integration.
    // ------------------------------------------------------------

    public function test_firm_authority_verified_badge_appears_once_actually_verified_and_disappears_after_revocation(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $admin = PlatformAdmin::factory()->create();
        $badges = app(MarketplaceBadgeService::class);

        $this->assertNotContains(MarketplaceBadge::FirmAuthorityVerified, $badges->badgesFor($firm->fresh()));

        $this->service->verify($firm, VerificationDimension::FirmAuthority, $admin, VerificationSource::AdminDocumentReview);
        $this->assertContains(MarketplaceBadge::FirmAuthorityVerified, $badges->badgesFor($firm->fresh()));

        $this->service->revoke($firm, VerificationDimension::FirmAuthority, $admin, 'no longer verifiable');
        $this->assertNotContains(MarketplaceBadge::FirmAuthorityVerified, $badges->badgesFor($firm->fresh()));
    }

    public function test_attorney_identity_verified_badge_appears_once_actually_verified(): void
    {
        $attorney = DirectoryAttorney::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $badges = app(MarketplaceBadgeService::class);

        $this->assertNotContains(MarketplaceBadge::AttorneyIdentityVerified, $badges->badgesForAttorney($attorney));

        $this->service->verify($attorney, VerificationDimension::AttorneyIdentity, $admin, VerificationSource::ThirdPartyBarRecord);

        $this->assertContains(MarketplaceBadge::AttorneyIdentityVerified, $badges->badgesForAttorney($attorney));
    }

    // ------------------------------------------------------------
    // RLS exemption.
    // ------------------------------------------------------------

    public function test_directory_verifications_table_is_genuinely_exempt_from_row_level_security(): void
    {
        $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', ['directory_verifications']);

        $this->assertNotNull($row, 'directory_verifications not found in pg_class.');
        $this->assertFalse((bool) $row->relrowsecurity, 'RLS must NOT be enabled on directory_verifications — it is platform-global data.');
        $this->assertFalse((bool) $row->relforcerowsecurity, 'FORCE RLS must NOT be enabled on directory_verifications.');
    }
}
