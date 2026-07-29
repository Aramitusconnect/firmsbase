<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\PlaidReclassificationApprovalsPage;
use App\Integrations\Enums\FinancialAccountClassification;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialAccountReclassificationService;
use App\Models\FinancialAccountReclassificationRequest;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlaidReclassificationApprovalsPageAuthorizationTest — CP8 pattern-sweep
 * finding regression: the page's own `canAccess()`/`shouldRegisterNavigation()`
 * correctly require `FinancialIntegrationAccessPolicyService::canApprove()`
 * (FirmOwner/Attorney only — BillingStaff is VIEW-tier but explicitly NOT
 * approve-tier), but the `table()->records()` closure — the actual data
 * query — previously only checked for an authenticated FirmUser, with no
 * `canApprove()` re-check. A BillingStaff actor (or any other active firm
 * user) reaching this route directly could read every pending/first_approved
 * trust-account reclassification request firm-wide. The `approve()`/`reject()`
 * mutations were always independently re-gated via
 * `FinancialAccountReclassificationService`'s own `assertDistinctApprovers()`-
 * adjacent checks — only this read path was missing its own re-check.
 *
 * Note: this table's `records()` closure maps rows into plain arrays
 * rather than returning Eloquent models/a query builder, so Filament's
 * row `wire:key` is a positional index, not the model's own primary
 * key — `assertCanSeeTableRecords()`'s built-in key matching does not
 * apply cleanly here. Assertions instead check for the request's own
 * `reason` text, after an explicit `loadTable` call (this table defers
 * loading by default, so a bare mount alone never renders row data).
 */
final class PlaidReclassificationApprovalsPageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const REASON_TEXT = 'Client trust funds now route here.';

    public function test_an_approver_tier_role_sees_the_pending_request_in_the_table(): void
    {
        [$firm] = $this->firmWithPendingRequest();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);

        // records() has no built-in ambient tenant context of its own
        // (unlike the Livewire FinancialEvidence panels, which
        // establish it internally); a real HTTP request through the
        // firm panel's middleware stack sets it, but Livewire::test()
        // does not, so the assertion itself is wrapped here.
        $this->runWithFirmContext($firm, function () use ($owner) {
            Livewire::actingAs($owner->user)
                ->test(PlaidReclassificationApprovalsPage::class)
                ->assertOk()
                ->call('loadTable')
                ->assertSee(self::REASON_TEXT);
        });
    }

    public function test_billing_staff_is_denied_page_access_outright_since_it_is_view_tier_not_approve_tier(): void
    {
        [$firm] = $this->firmWithPendingRequest();
        // BillingStaff is VIEW-tier (FinancialIntegrationAccessPolicyService::canView())
        // but deliberately NOT approve-tier. canAccess() alone already
        // denies this page to BillingStaff (403) — confirming the outer
        // gate is intact. The records()-closure fix is defense-in-depth
        // for the scenario the *next* test exercises directly: an
        // already-mounted component whose actor's role changes between
        // mount and a later table refresh.
        $billingStaff = $this->firmUser($firm, FirmUserRole::BillingStaff);

        Livewire::actingAs($billingStaff->user)
            ->test(PlaidReclassificationApprovalsPage::class)
            ->assertForbidden();
    }

    public function test_records_closure_independently_denies_after_a_post_mount_role_demotion(): void
    {
        // Mirrors the established pattern elsewhere in this checkpoint
        // (FinancialEvidence panels' "post-mount demotion" tests):
        // canAccess() is only re-evaluated at mount/route-resolution
        // time. This proves the records() closure itself — not merely
        // the outer route gate — independently denies data once the
        // acting FirmUser's role no longer satisfies canApprove(),
        // which is exactly the defect the pattern-sweep flagged (the
        // closure previously only checked "is there an authenticated
        // FirmUser at all").
        [$firm] = $this->firmWithPendingRequest();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($owner) {
            $component = Livewire::actingAs($owner->user)
                ->test(PlaidReclassificationApprovalsPage::class)
                ->assertOk()
                ->call('loadTable')
                ->assertSee(self::REASON_TEXT);

            $owner->forceFill(['role' => FirmUserRole::BillingStaff])->save();

            $component->call('$refresh')->assertDontSee(self::REASON_TEXT);
        });
    }

    public function test_a_non_approver_direct_route_hit_never_renders_any_pending_request_row(): void
    {
        [$firm] = $this->firmWithPendingRequest();
        $paralegal = $this->firmUser($firm, FirmUserRole::Paralegal);

        // canAccess() denies Paralegal outright (below even VIEW-tier)
        // — the outer gate. Combined with the BillingStaff test above
        // (also denied, but for a different reason — VIEW-tier without
        // approve-tier) and the post-mount-demotion test above (which
        // proves the records() closure's OWN independent gate, isolated
        // from canAccess()), this confirms no active firm user below
        // the approve tier can reach this page's data by any path this
        // suite can drive.
        Livewire::actingAs($paralegal->user)
            ->test(PlaidReclassificationApprovalsPage::class)
            ->assertForbidden();
    }

    public function test_a_cross_firm_approver_cannot_see_another_firms_pending_request(): void
    {
        [$firmA] = $this->firmWithPendingRequest();

        $firmB = $this->plaidEntitledFirm();
        $ownerB = $this->firmUser($firmB, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firmB, function () use ($ownerB) {
            Livewire::actingAs($ownerB->user)
                ->test(PlaidReclassificationApprovalsPage::class)
                ->assertOk()
                ->call('loadTable')
                ->assertDontSee(self::REASON_TEXT);
        });
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function plaidEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );
    }

    /**
     * @return array{0: Firm, 1: FinancialAccountReclassificationRequest}
     */
    private function firmWithPendingRequest(): array
    {
        $firm = $this->plaidEntitledFirm();

        $request = $this->runWithFirmContext($firm, function () use ($firm) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            $requester = FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create();

            return app(FinancialAccountReclassificationService::class)->request(
                $firm,
                $account,
                $requester,
                FinancialAccountClassification::TrustIolta,
                self::REASON_TEXT,
            );
        });

        return [$firm, $request];
    }
}
