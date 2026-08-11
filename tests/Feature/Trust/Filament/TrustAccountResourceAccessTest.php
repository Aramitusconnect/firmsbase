<?php

declare(strict_types=1);

namespace Tests\Feature\Trust\Filament;

use App\Enums\FirmUserRole;
use App\Enums\TrustAccountStatus;
use App\Filament\Firm\Resources\TrustAccountResource;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\CloseTrustAccountAction;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\OpenTrustAccountAction;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\SuspendTrustAccountAction;
use App\Filament\Firm\Resources\TrustAccountResource\Pages\ListTrustAccounts;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\User;
use App\Services\Security\StepUpAuthenticationService;
use App\Services\TrustAccountService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * TrustAccountResourceAccessTest — proves Open/Suspend/Close each call
 * the real TrustAccountService method (state-verified, never a bare
 * write), role ceilings (canApprove-gated — see OpenTrustAccountAction's
 * own docblock for why), status-based visibility, and the small RLS
 * regression checklist for trust_accounts (a BelongsToTenant + FORCE
 * RLS table).
 */
final class TrustAccountResourceAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_open_trust_account_action_is_visible_for_firm_owner_and_hidden_for_billing_staff(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListTrustAccounts::class));
        $test->assertActionVisible(OpenTrustAccountAction::getDefaultName());

        $firm2 = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm2, FirmUserRole::BillingStaff);

        $test2 = $this->runWithFirmContext($firm2, fn () => Livewire::test(ListTrustAccounts::class));
        $test2->assertActionHidden(OpenTrustAccountAction::getDefaultName());
    }

    public function test_open_trust_account_action_calls_the_real_service(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListTrustAccounts::class);
            $test->mountAction(OpenTrustAccountAction::getDefaultName());
            $test->setActionData(['account_name' => 'Firm IOLTA Trust Account', 'bank_name_reference' => 'Test Bank']);
            $test->callMountedAction();
            $test->assertNotified('Trust account opened');
        });

        $account = $this->runWithFirmContext($firm, fn () => TrustAccount::query()->where('firm_id', $firm->id)->first());
        $this->assertNotNull($account);
        $this->assertSame('Firm IOLTA Trust Account', $account->account_name);
        $this->assertSame(TrustAccountStatus::Active, $account->status);
        $this->assertNotNull($account->opened_at);
    }

    public function test_suspend_and_close_transition_the_account_via_the_service(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Test Account'));
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ListTrustAccounts::class);
            $test->callTableAction(SuspendTrustAccountAction::getDefaultName(), $account);
        });

        $suspended = $this->runWithFirmContext($firm, fn () => TrustAccount::query()->find($account->id));
        $this->assertSame(TrustAccountStatus::Suspended, $suspended->status);

        // Mission 1B (Extreme Security Hardening), section 47:
        // CloseTrustAccountAction now requires a recent step-up
        // verification (see StepUpAuthenticationTest for that
        // mechanism's own coverage) — marking it verified here proves
        // this test's own concern (the action calls the real service)
        // without re-testing step-up itself.
        app(StepUpAuthenticationService::class)->markVerified('web');

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ListTrustAccounts::class);
            $test->callTableAction(CloseTrustAccountAction::getDefaultName(), $account);
        });

        $closed = $this->runWithFirmContext($firm, fn () => TrustAccount::query()->find($account->id));
        $this->assertSame(TrustAccountStatus::Closed, $closed->status);
    }

    public function test_close_trust_account_fails_without_a_recent_step_up_verification(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Test Account'));
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        // No StepUpAuthenticationService::markVerified() call — the
        // action must require the step-up password field and refuse
        // to close the account without it.
        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ListTrustAccounts::class);
            $test->callTableAction(CloseTrustAccountAction::getDefaultName(), $account);
            $test->assertHasTableActionErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TrustAccount::query()->find($account->id));
        $this->assertSame(TrustAccountStatus::Active, $fresh->status, 'The account must remain open when step-up authentication is not satisfied.');
    }

    public function test_suspend_action_is_hidden_once_already_closed(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $service = app(TrustAccountService::class);
        $account = $this->runWithFirmContext($firm, function () use ($firm, $service) {
            $account = $service->open($firm, 'Test Account');

            return $service->close($firm, $account);
        });
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ListTrustAccounts::class);
            $test->assertTableActionHidden(SuspendTrustAccountAction::getDefaultName(), $account);
            $test->assertTableActionHidden(CloseTrustAccountAction::getDefaultName(), $account);
        });
    }

    public function test_billing_staff_cannot_suspend_an_account_even_if_the_action_is_forced(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Test Account'));
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ListTrustAccounts::class);
            $test->assertTableActionHidden(SuspendTrustAccountAction::getDefaultName(), $account);
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TrustAccount::query()->find($account->id));
        $this->assertSame(TrustAccountStatus::Active, $fresh->status);
    }

    // ------------------------------------------------------------
    // Small RLS/tenant-boundary regression checklist (trust_accounts —
    // BelongsToTenant + FORCE RLS)
    // ------------------------------------------------------------

    public function test_a_firm_user_can_access_its_own_trust_account(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Own Account'));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TrustAccountResource::getUrl('view', ['record' => $account])));

        $response->assertSuccessful();
    }

    public function test_list_page_shows_only_this_firms_trust_accounts(): void
    {
        $firmA = $this->makeTrustEligibleFirm();
        $firmB = $this->makeTrustEligibleFirm();
        $accountA = $this->runWithFirmContext($firmA, fn () => app(TrustAccountService::class)->open($firmA, 'Firm A Account'));
        $accountB = $this->runWithFirmContext($firmB, fn () => app(TrustAccountService::class)->open($firmB, 'Firm B Account'));
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListTrustAccounts::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$accountA]);
        $test->assertCanNotSeeTableRecords([$accountB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_trust_account_row(): void
    {
        $firmA = $this->makeTrustEligibleFirm();
        $firmB = $this->makeTrustEligibleFirm();
        $accountA = $this->runWithFirmContext($firmA, fn () => app(TrustAccountService::class)->open($firmA, 'Firm A Account'));
        $accountB = $this->runWithFirmContext($firmB, fn () => app(TrustAccountService::class)->open($firmB, 'Firm B Account'));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('trust_accounts')->pluck('id')->all());

        $this->assertContains($accountA->id, $visibleIds);
        $this->assertNotContains($accountB->id, $visibleIds, "Firm A's session must never read Firm B's trust account row.");
    }

    public function test_direct_url_guess_of_another_firms_trust_account_never_succeeds(): void
    {
        $firmA = $this->makeTrustEligibleFirm();
        $firmB = $this->makeTrustEligibleFirm();
        $accountB = $this->runWithFirmContext($firmB, fn () => app(TrustAccountService::class)->open($firmB, 'Firm B Account'));
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TrustAccountResource::getUrl('view', ['record' => $accountB])));

        $response->assertNotFound();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
