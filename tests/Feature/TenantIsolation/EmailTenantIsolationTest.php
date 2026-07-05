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

        (new TenantContextResolver())->activateForFirm($firmA);

        $visible = EmailAccount::query()->get();

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

        (new TenantContextResolver())->activateForFirm($firmB);

        $visible = EmailMessage::query()->get();

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
