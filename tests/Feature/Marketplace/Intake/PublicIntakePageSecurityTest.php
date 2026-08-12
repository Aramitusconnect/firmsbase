<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Models\FirmLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 2 —
 * public-route security for `/intake/{uuid}`, mirroring
 * PublicPaymentPageSecurityTest exactly. Every test below proves the
 * page cannot be used to disclose or corrupt anything beyond the
 * exact intake its own signed URL names.
 */
class PublicIntakePageSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function freshIntake(): array
    {
        $firm = Firm::factory()->create();
        $intake = app(MarketplaceIntakeService::class)->start($firm);

        return [$firm, $intake];
    }

    public function test_a_validly_signed_url_for_a_resumable_intake_renders_successfully(): void
    {
        [$firm, $intake] = $this->freshIntake();
        $url = app(MarketplaceIntakeService::class)->signedUrl($intake);

        $response = $this->get($url);

        $response->assertOk();
        // Mission 3A (MyAttorney Launch-Flow Closure) — a fresh,
        // never-touched intake lands on the disclosure step first, the
        // real wizard's own entry point (superseding this checkpoint
        // 2 test's original "saved and ready to continue" placeholder
        // assertion, which only ever covered the resume/status shell).
        $response->assertSee('Before you begin', false);
    }

    public function test_the_bare_route_with_no_signature_at_all_is_rejected(): void
    {
        [$firm, $intake] = $this->freshIntake();

        $response = $this->get($this->myAttorneyUrl('/intake/'.$intake->uuid));

        $response->assertForbidden();
    }

    public function test_a_tampered_uuid_against_someone_elses_signature_is_rejected(): void
    {
        [$firmA, $intakeA] = $this->freshIntake();
        [$firmB, $intakeB] = $this->freshIntake();

        $urlForA = app(MarketplaceIntakeService::class)->signedUrl($intakeA);
        $tamperedUrl = str_replace($intakeA->uuid, $intakeB->uuid, $urlForA);

        $response = $this->get($tamperedUrl);

        $response->assertForbidden();
    }

    public function test_an_expired_signature_is_rejected(): void
    {
        [$firm, $intake] = $this->freshIntake();

        $expiredUrl = URL::temporarySignedRoute('public.marketplace-intakes.show', now()->subMinute(), ['uuid' => $intake->uuid]);

        $response = $this->get($expiredUrl);

        $response->assertForbidden();
    }

    public function test_a_modified_query_parameter_invalidates_the_signature(): void
    {
        [$firm, $intake] = $this->freshIntake();
        $url = app(MarketplaceIntakeService::class)->signedUrl($intake);

        $response = $this->get($url.'&injected=1');

        $response->assertForbidden();
    }

    public function test_a_genuinely_unknown_but_well_formed_uuid_never_validates(): void
    {
        $unknownUuid = (string) Str::uuid7();

        $response = $this->get($this->myAttorneyUrl('/intake/'.$unknownUuid.'?expires=9999999999&signature=deadbeef'));

        $response->assertForbidden();
    }

    public function test_a_row_level_expired_intake_still_loads_but_reports_not_resumable(): void
    {
        // DB-side expiry (expires_at) is independent of the
        // signature's own cryptographic expiry — the signed URL keeps
        // validating right up to its own expires_at (30 days by
        // default), but the page must show the intake is no longer
        // available once the ROW itself has expired, mirroring
        // PublicPaymentPageSecurityTest's revoked-request case.
        [$firm, $intake] = $this->freshIntake();
        $this->runWithFirmContext($firm, fn () => $intake->update(['expires_at' => now()->subDay()]));

        $url = URL::temporarySignedRoute('public.marketplace-intakes.show', now()->addDay(), ['uuid' => $intake->uuid]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('no longer available', false);
        $response->assertDontSee('saved and ready to continue', false);
    }

    public function test_a_declined_intakes_link_never_shows_as_resumable(): void
    {
        [$firm, $intake] = $this->freshIntake();
        $this->runWithFirmContext($firm, fn () => $intake->update(['status' => 'declined', 'declined_at' => now()]));

        $url = app(MarketplaceIntakeService::class)->signedUrl($intake);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('no longer available', false);
    }

    public function test_the_page_never_discloses_the_firms_other_clients_or_leads(): void
    {
        [$firm, $intake] = $this->freshIntake();
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create(['name' => 'Some Other Confidential Lead']));
        $url = app(MarketplaceIntakeService::class)->signedUrl($intake);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertDontSee('Some Other Confidential Lead', false);
    }

    public function test_repeated_requests_past_the_throttle_limit_are_rejected(): void
    {
        [$firm, $intake] = $this->freshIntake();
        $url = app(MarketplaceIntakeService::class)->signedUrl($intake);

        $lastStatus = null;
        for ($i = 0; $i < 35; $i++) {
            $lastStatus = $this->get($url)->getStatusCode();
        }

        $this->assertSame(429, $lastStatus, 'The public intake page must be rate-limited (throttle:30,1) — 35 requests in a row must eventually be rejected.');
    }
}
