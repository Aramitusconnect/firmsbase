<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Correction;

use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;
use App\Marketplace\Services\MarketplaceCorrectionService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CorrectionWorkflowTest — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 8. Section 51: correction/removal workflow, canonical
 * states, full audit trail. Section 25: lightweight profile
 * versioning, wired through resolve()'s optional field changes.
 */
final class CorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceCorrectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MarketplaceCorrectionService::class);
    }

    // ------------------------------------------------------------
    // State machine.
    // ------------------------------------------------------------

    public function test_submitting_a_correction_creates_a_pending_request(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();

        $request = $this->service->submit($firm, CorrectionType::IncorrectPhone, 'The phone number is wrong.', 'Jane Doe', 'jane@example.com');

        $this->assertSame(CorrectionState::Pending, $request->state);
        $this->assertSame(CorrectionType::IncorrectPhone, $request->correction_type);
        $this->assertSame('The phone number is wrong.', $request->description);
        $this->assertSame('Jane Doe', $request->reporter_name);
        $this->assertNull($request->reporter_firm_user_id);
    }

    public function test_mark_under_review_transitions_from_pending(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::DuplicateListing, 'Duplicate of another listing.');
        $admin = PlatformAdmin::factory()->create();

        $updated = $this->service->markUnderReview($request, $admin);

        $this->assertSame(CorrectionState::UnderReview, $updated->state);
    }

    public function test_mark_under_review_from_a_non_pending_state_is_rejected(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::DuplicateListing, 'Duplicate.');
        $admin = PlatformAdmin::factory()->create();
        $this->service->markUnderReview($request, $admin);

        $this->expectException(\RuntimeException::class);
        $this->service->markUnderReview($request->fresh(), $admin);
    }

    public function test_approve_transitions_from_pending_or_under_review(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::FirmClosed, 'Firm shut down last year.');
        $admin = PlatformAdmin::factory()->create();

        $approved = $this->service->approve($request, $admin, 'Confirmed via state bar records.');

        $this->assertSame(CorrectionState::Approved, $approved->state);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame('Confirmed via state bar records.', $approved->reviewer_notes);
    }

    public function test_approve_an_already_approved_request_is_rejected(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::FirmClosed, 'Firm shut down.');
        $admin = PlatformAdmin::factory()->create();
        $this->service->approve($request, $admin);

        $this->expectException(\RuntimeException::class);
        $this->service->approve($request->fresh(), $admin);
    }

    public function test_reject_transitions_with_a_reason_and_never_touches_the_directory_firm(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create(['phone' => '5555550100']);
        $request = $this->service->submit($firm, CorrectionType::IncorrectPhone, 'Wrong number.');
        $admin = PlatformAdmin::factory()->create();

        $rejected = $this->service->reject($request, $admin, 'Could not independently confirm.');

        $this->assertSame(CorrectionState::Rejected, $rejected->state);
        $this->assertSame('Could not independently confirm.', $rejected->rejection_reason);
        $this->assertSame('5555550100', $firm->fresh()->phone);
    }

    public function test_resolve_requires_prior_approval(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::IncorrectPhone, 'Wrong number.');
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->resolve($request, $admin, 'fixed');
    }

    public function test_resolve_applies_allowed_field_changes_and_records_a_profile_version(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create(['phone' => '5555550100']);
        $request = $this->service->submit($firm, CorrectionType::IncorrectPhone, 'Wrong number.');
        $admin = PlatformAdmin::factory()->create();
        $this->service->approve($request, $admin);

        $resolved = $this->service->resolve($request->fresh(), $admin, 'Updated phone number.', ['phone' => '5555550199']);

        $this->assertSame(CorrectionState::Resolved, $resolved->state);
        $this->assertSame('5555550199', $firm->fresh()->phone);

        $version = DirectoryProfileVersion::query()->where('directory_firm_id', $firm->id)->first();
        $this->assertNotNull($version, 'resolve() with field changes must record a profile version.');
        $this->assertSame(['phone' => '5555550199'], $version->changed_fields);
        $this->assertSame('platform_admin', $version->actor_type);
        $this->assertSame($admin->id, $version->actor_id);
    }

    public function test_resolve_never_applies_a_disallowed_field_even_if_submitted(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::RemovalRequest, 'Please remove this listing.');
        $admin = PlatformAdmin::factory()->create();
        $this->service->approve($request, $admin);

        $this->service->resolve($request->fresh(), $admin, 'n/a', [
            'phone' => '5555550199',
            'is_claimed' => true,
            'firm_id' => 999999,
            'publication_state' => 'removed',
        ]);

        $fresh = $firm->fresh();
        $this->assertSame('5555550199', $fresh->phone, 'The allowlisted field must still apply.');
        $this->assertFalse($fresh->is_claimed, 'is_claimed must never be settable through resolve().');
        $this->assertNull($fresh->firm_id, 'firm_id must never be settable through resolve().');
        $this->assertNotEquals('removed', $fresh->publication_state->value, 'publication_state must never be settable through resolve().');

        $version = DirectoryProfileVersion::query()->where('directory_firm_id', $firm->id)->first();
        $this->assertArrayNotHasKey('is_claimed', $version->changed_fields);
        $this->assertArrayNotHasKey('firm_id', $version->changed_fields);
        $this->assertArrayNotHasKey('publication_state', $version->changed_fields);
    }

    public function test_resolve_with_no_field_changes_records_no_profile_version(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();
        $request = $this->service->submit($firm, CorrectionType::AttorneyNoLongerAtFirm, 'That attorney left.');
        $admin = PlatformAdmin::factory()->create();
        $this->service->approve($request, $admin);

        $this->service->resolve($request->fresh(), $admin, 'Relationship record updated separately.');

        $this->assertSame(0, DirectoryProfileVersion::query()->where('directory_firm_id', $firm->id)->count());
    }

    // ------------------------------------------------------------
    // Profile version model — append-only.
    // ------------------------------------------------------------

    public function test_a_profile_version_row_can_never_be_updated_or_deleted(): void
    {
        $version = DirectoryProfileVersion::factory()->create();

        $this->expectException(\LogicException::class);
        $version->update(['actor_type' => 'system']);
    }

    // ------------------------------------------------------------
    // Audit routing.
    // ------------------------------------------------------------

    public function test_submitting_a_correction_on_an_unclaimed_listing_records_a_public_visitor_audit_event(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();

        $this->service->submit($firm, CorrectionType::DuplicateListing, 'Duplicate.');

        $event = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->whereNull('firm_id')
            ->where('event_type', 'marketplace_correction_submitted')
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame('public_visitor', $event->actor_type);
        $this->assertNull($event->actor_id);
    }

    public function test_admin_transitions_on_a_claimed_listing_are_audited_against_its_linked_tenant_firm(): void
    {
        $tenantFirm = Firm::factory()->create();
        $firm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $tenantFirm->id]);
        $request = $this->service->submit($firm, CorrectionType::IncorrectAddress, 'Wrong address.');
        $admin = PlatformAdmin::factory()->create();

        $this->service->approve($request, $admin);

        $event = $this->runWithFirmContext($tenantFirm, fn () => SecurityEvent::query()
            ->where('event_type', 'marketplace_correction_approved')
            ->where('firm_id', $tenantFirm->id)
            ->first());

        $this->assertNotNull($event);
    }

    // ------------------------------------------------------------
    // Public HTTP surface.
    // ------------------------------------------------------------

    public function test_the_report_correction_form_renders_for_a_published_listing(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'report-me', 'display_name' => 'Report Me Law']);

        $response = $this->get($this->myAttorneyUrl('/firms/report-me/report-correction'));

        $response->assertOk();
        $response->assertSee('Report a Correction');
        $response->assertSee('Report Me Law');
    }

    public function test_the_report_correction_form_404s_for_a_draft_listing(): void
    {
        DirectoryFirm::factory()->draft()->create(['slug' => 'hidden-firm']);

        $this->get($this->myAttorneyUrl('/firms/hidden-firm/report-correction'))->assertNotFound();
    }

    public function test_submitting_the_public_form_creates_a_correction_request_and_redirects_with_a_flash(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'submit-me']);

        $response = $this->from($this->myAttorneyUrl('/firms/submit-me/report-correction'))
            ->post($this->myAttorneyUrl('/firms/submit-me/report-correction'), [
                'correction_type' => CorrectionType::IncorrectPhone->value,
                'description' => 'Their phone number is disconnected.',
                'reporter_email' => 'reporter@example.com',
                '_token' => csrf_token(),
            ]);

        $response->assertRedirect($this->myAttorneyUrl('/firms/submit-me'));
        $response->assertSessionHas('correction_submitted', true);

        $this->assertSame(1, DirectoryCorrectionRequest::query()->where('directory_firm_id', $firm->id)->count());
    }

    public function test_submitting_the_public_form_without_a_description_fails_validation(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'validate-me']);

        $response = $this->from($this->myAttorneyUrl('/firms/validate-me/report-correction'))
            ->post($this->myAttorneyUrl('/firms/validate-me/report-correction'), [
                'correction_type' => CorrectionType::IncorrectPhone->value,
                '_token' => csrf_token(),
            ]);

        $response->assertSessionHasErrors('description');
        $this->assertSame(0, DirectoryCorrectionRequest::query()->where('directory_firm_id', $firm->id)->count());
    }

    public function test_submitting_the_public_form_with_an_invalid_correction_type_fails_validation(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'validate-type']);

        $response = $this->from($this->myAttorneyUrl('/firms/validate-type/report-correction'))
            ->post($this->myAttorneyUrl('/firms/validate-type/report-correction'), [
                'correction_type' => 'not_a_real_type',
                'description' => 'Something is wrong.',
                '_token' => csrf_token(),
            ]);

        $response->assertSessionHasErrors('correction_type');
    }

    /**
     * Laravel's own PreventRequestForgery::runningUnitTests() (the
     * class VerifyCsrfToken extends) unconditionally bypasses CSRF
     * verification whenever app()->runningInConsole() &&
     * app()->runningUnitTests() — true for every request made through
     * `php artisan test`, on every route in this application, not just
     * this one. A live 419-on-forged-POST assertion is therefore not
     * meaningful in this harness (confirmed empirically: the identical
     * limitation already exists in
     * tests/Feature/Security/Hosts/SessionCookieIsolationTest.php's own
     * CSRF test, which accepts [419, 405] rather than requiring 419
     * for exactly this reason). What IS provable here: the route sits
     * behind the standard `web` middleware group (VerifyCsrfToken is
     * active in that group in every non-testing environment — see
     * routes/web.php's own docblock), and the rendered form actually
     * carries a real @csrf token, not a form that silently omits one.
     */
    public function test_the_report_correction_form_includes_a_real_csrf_token(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'csrf-me']);

        $response = $this->get($this->myAttorneyUrl('/firms/csrf-me/report-correction'));

        $response->assertOk();
        $response->assertSee('name="_token"', false);
    }

    public function test_the_correction_report_form_sets_its_own_distinctly_named_host_only_session_cookie(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'cookie-me']);

        $response = $this->get($this->myAttorneyUrl('/firms/cookie-me/report-correction'));

        $cookie = null;
        foreach ($response->headers->getCookies() as $candidate) {
            if ($candidate->getName() === 'firmsvault-myattorney-session') {
                $cookie = $candidate;
            }
        }

        $this->assertNotNull($cookie, 'Expected a firmsvault-myattorney-session cookie, distinct from every Filament panel\'s own session cookie.');
        $this->assertNull($cookie->getDomain(), 'The MyAttorney session cookie must be host-only (no Domain attribute) — never widen a .firmsvault.com cookie.');
    }

    // ------------------------------------------------------------
    // RLS exemption.
    // ------------------------------------------------------------

    public function test_correction_and_version_tables_are_genuinely_exempt_from_row_level_security(): void
    {
        foreach (['directory_correction_requests', 'directory_profile_versions'] as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "{$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relrowsecurity, "RLS must NOT be enabled on {$table}.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "FORCE RLS must NOT be enabled on {$table}.");
        }
    }
}
