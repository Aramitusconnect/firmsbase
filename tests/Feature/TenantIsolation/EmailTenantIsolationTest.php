<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Firm;
use App\Services\TenantContextResolver;
use App\Services\TenantSafeEmailPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmailTenantIsolationTest — mandatory per approved Phase 9 tenant
 * rules. Confirms EmailAccount/EmailMessage application-level isolation
 * both via BelongsToTenant's global scope and via
 * TenantSafeEmailPolicyService's explicit guard, mirroring Phase 8's
 * ImportExportTenantIsolationTest.
 *
 * Narrowly updated by Section 39A-5 Wave 5 (real bug-in-waiting caught
 * during independent test review, before it could regress silently):
 * email_accounts/email_messages now have permanent FORCE ROW LEVEL
 * SECURITY (database/migrations/2026_08_27_950025/950026_...). The two
 * "global scope narrows" tests below used to activate ONLY PHP-memory
 * tenant context via TenantContextResolver::activateForFirm() before
 * reading, while the preceding EmailAccount::factory()->forFirm(...)
 * ->create() calls (via EmailAccountFactory's new context-hold
 * create() override) leave the DATABASE session's own
 * app.current_firm_id set to whichever firm was created LAST — not
 * necessarily the firm this test activates PHP-memory context for.
 * Once FORCE is active, BelongsToTenant's own PHP-side "WHERE
 * firm_id = X" global-scope clause and RLS's OWN independent
 * "WHERE firm_id = (whatever the DB session says)" clause are BOTH
 * applied to the same query — if they disagree, the combined result is
 * a self-contradictory WHERE clause that matches zero rows, not the
 * one row the test expects. Fixed here by wrapping each read itself in
 * runWithFirmContext() (this project's established helper — see e.g.
 * MatterExpenseServiceTest.php), which establishes BOTH the PHP-memory
 * AND the database-session tenant context together, and restores
 * whatever was active before once the callback returns.
 */
class EmailTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }

    public function test_email_account_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        EmailAccount::factory()->forFirm($firmA)->create();
        EmailAccount::factory()->forFirm($firmB)->create();

        $visible = $this->runWithFirmContext($firmA, fn () => EmailAccount::query()->get());

        $this->assertCount(1, $visible);
        $this->assertSame($firmA->id, $visible->first()->firm_id);
    }

    public function test_email_message_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountA = EmailAccount::factory()->forFirm($firmA)->create();
        $accountB = EmailAccount::factory()->forFirm($firmB)->create();
        EmailMessage::factory()->forAccount($accountA)->create();
        EmailMessage::factory()->forAccount($accountB)->create();

        $visible = $this->runWithFirmContext($firmB, fn () => EmailMessage::query()->get());

        $this->assertCount(1, $visible);
        $this->assertSame($firmB->id, $visible->first()->firm_id);
    }

    public function test_tenant_safe_policy_service_rejects_cross_firm_email_account_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firmA)->create();

        $this->expectException(TenantIsolationException::class);

        (new TenantSafeEmailPolicyService())->assertEmailAccountBelongsToFirm($account, $firmB);
    }

    public function test_tenant_safe_policy_service_rejects_cross_firm_email_message_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firmA)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();

        $this->expectException(TenantIsolationException::class);

        (new TenantSafeEmailPolicyService())->assertEmailMessageBelongsToFirm($message, $firmB);
    }

    public function test_email_oauth_token_has_no_firm_id_column_and_is_scoped_transitively(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('email_oauth_tokens');

        $this->assertNotContains('firm_id', $columns);
        $this->assertContains('email_account_id', $columns);
    }
}
