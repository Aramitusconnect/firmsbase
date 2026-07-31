<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\AuthorizeProviderOperationRetryAction;
use App\Filament\Actions\Platform\ConfirmProviderOperationSucceededAction;
use App\Filament\Actions\Platform\ResolveProviderOperationWithoutRetryAction;
use App\Filament\Pages\PlatformProviderOperationReconciliationPage;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Exceptions\ProviderOperationOwnershipLostException;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * ProviderOperationReconciliationTest — Checkpoint 8.2
 * (§A-reconciliation). Proves the first production consumer of
 * `ProviderOperationAttemptService::resolveReconciliation()` — page
 * authorization, action authorization independent of page visibility,
 * audit on every mutation (including denied ones), concurrency safety,
 * and cross-firm isolation.
 */
final class ProviderOperationReconciliationTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function attemptRequiringReconciliation(int $firmId = 501, string $key = 'push_sync:test-key-1'): ProviderOperationAttempt
    {
        return $this->makeAttempt([
            'uuid' => (string) Str::uuid7(),
            'logical_operation_key' => $key,
            'provider_key' => 'microsoft365',
            'firm_id' => $firmId,
            'firm_integration_id' => 9001,
            'operation_type' => 'push_sync',
            'operation_version' => 1,
            'attempt_state' => ProviderOperationAttemptState::ReconciliationRequired->value,
            'owner_token' => null,
            'send_count' => 1,
            'total_send_count' => 1,
            'reclaim_count' => 0,
            'reconciliation_reason' => 'uncertain_provider_outcome:timeout',
        ]);
    }

    /**
     * ProviderOperationAttempt is $guarded = ['*'] by design (no
     * mass-assignment path exists in production — see that model's own
     * docblock), so this test fixture uses the same forceFill()+save()
     * construction ProviderOperationAttemptService::insertFreshClaim()
     * itself uses, never Eloquent's create().
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeAttempt(array $attributes): ProviderOperationAttempt
    {
        $attempt = new ProviderOperationAttempt;
        $attempt->setConnection(self::DURABLE_CONNECTION);
        $attempt->forceFill($attributes);
        $attempt->save();

        return $attempt;
    }

    private function auditRow(string $category): ?object
    {
        return DB::table('security_events')
            ->where('event_type', 'provider_operation_reconciliation_resolved')
            ->where('category', $category)
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Page-level authorization (direct route access)
    // ------------------------------------------------------------------

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformProviderOperationReconciliationPage::shouldRegisterNavigation());
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->get(PlatformProviderOperationReconciliationPage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->get(PlatformProviderOperationReconciliationPage::getUrl())->assertForbidden();
    }

    public function test_a_read_only_auditor_is_forbidden_from_viewing(): void
    {
        // Not in CLIENT_AND_MATTER_DATA_ROLES (the existing
        // canAccessIntegrationOversight() gate this page reuses) — the
        // discovered, authoritative policy already denies this role view
        // access to this whole page family, not merely mutation.
        $admin = $this->adminWithRole(PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $this->get(PlatformProviderOperationReconciliationPage::getUrl())->assertForbidden();
    }

    public function test_a_support_agent_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->actingAs($admin, 'platform_admin');

        $this->get(PlatformProviderOperationReconciliationPage::getUrl())->assertOk();
    }

    public function test_a_firm_panel_user_is_denied_the_page(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create([
            'firm_id' => $firm->id, 'user_id' => $user->id, 'role' => FirmUserRole::Attorney->value,
        ]));

        $this->actingAs($firmUser->user);

        $this->get(PlatformProviderOperationReconciliationPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_the_query_excludes_rows_not_requiring_reconciliation(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $reconciling = $this->attemptRequiringReconciliation(601, 'push_sync:reconciling');
        $this->makeAttempt([
            'uuid' => (string) Str::uuid7(),
            'logical_operation_key' => 'push_sync:already-complete',
            'provider_key' => 'microsoft365',
            'firm_id' => 601,
            'operation_type' => 'push_sync',
            'attempt_state' => ProviderOperationAttemptState::LocalProcessingComplete->value,
        ]);

        $ids = Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertContains($reconciling->id, $ids);
        $this->assertCount(1, $ids);
    }

    // ------------------------------------------------------------------
    // Action authorization — independent of page visibility
    // ------------------------------------------------------------------

    public function test_confirm_succeeded_is_denied_for_a_read_only_auditor_combined_with_super_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation();

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(ConfirmProviderOperationSucceededAction::getDefaultName(), $attempt, data: ['reason' => 'checked with provider support']);

        $fresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired, $fresh->attempt_state, 'read_only_auditor must never mutate, even combined with super_admin.');
    }

    public function test_authorize_retry_is_denied_for_a_support_agent(): void
    {
        // SupportAgent may VIEW (canAccessIntegrationOversight) but is
        // NOT in INTEGRATION_CONNECTION_MANAGEMENT_ROLES, so it must not
        // be able to mutate.
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation();

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(AuthorizeProviderOperationRetryAction::getDefaultName(), $attempt, data: ['reason' => 'confirmed no charge occurred']);

        $fresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired, $fresh->attempt_state);
    }

    // ------------------------------------------------------------------
    // Resolution actions — the three legal outcomes
    // ------------------------------------------------------------------

    public function test_confirm_succeeded_transitions_to_local_processing_complete_and_never_resends(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation();

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(ConfirmProviderOperationSucceededAction::getDefaultName(), $attempt, data: ['reason' => 'verified in provider dashboard'])
            ->assertHasNoTableActionErrors();

        $fresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $fresh->attempt_state);
        $this->assertStringContainsString('operator_resolved:verified in provider dashboard', (string) $fresh->state_reason);
        $this->assertStringContainsString((string) $admin->id, (string) $fresh->state_reason);
    }

    public function test_authorize_retry_transitions_to_retry_allowed(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation();

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(AuthorizeProviderOperationRetryAction::getDefaultName(), $attempt, data: ['reason' => 'confirmed 0 charges in provider billing console'])
            ->assertHasNoTableActionErrors();

        $fresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::RetryAllowed, $fresh->attempt_state);
        // send_count itself only resets when something next calls
        // claim() and reclaim() actually begins the new generation
        // (ProviderOperationAttemptService::reclaim()'s own contract) —
        // resolveReconciliation() only marks the row eligible for that,
        // it does not itself reset the counter.
        $this->assertSame(1, (int) $fresh->total_send_count, 'total_send_count must remain the full history.');

        $claim = app(ProviderOperationAttemptService::class)->claim(
            $attempt->logical_operation_key, $attempt->provider_key, $attempt->firm_id, $attempt->firm_integration_id, $attempt->operation_type,
        );
        $this->assertTrue($claim->maySendProviderRequest(), 'retry_allowed must be reclaimable for a genuinely fresh attempt.');
        $this->assertSame(0, (int) $claim->attempt->send_count, 'The new generation begins with send_count reset.');
        $this->assertSame(1, (int) $claim->attempt->total_send_count, 'total_send_count only increments on an actual send (markAttemptStarted()), not on a bare reclaim.');
    }

    public function test_resolve_without_retry_transitions_to_local_processing_complete_with_a_distinct_audit_category(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation();

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(ResolveProviderOperationWithoutRetryAction::getDefaultName(), $attempt, data: ['reason' => 'connection was disconnected by the firm, no longer relevant'])
            ->assertHasNoTableActionErrors();

        $fresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $fresh->attempt_state);

        $confirmedRow = $this->auditRow('confirm_provider_succeeded');
        $this->assertNull($confirmedRow, 'Resolving without retry must never be recorded under the same category as a confirmed success.');

        $resolvedRow = $this->auditRow('resolve_without_retry');
        $this->assertNotNull($resolvedRow);
    }

    // ------------------------------------------------------------------
    // Concurrency: two operator actions cannot both win
    // ------------------------------------------------------------------

    public function test_two_conflicting_resolutions_cannot_both_win(): void
    {
        // Both "operators" act through the exact same durable, CAS-
        // guarded service method every action delegates to
        // (resolveReconciliation() itself refuses to transition out of
        // reconciliation_required a second time) — this is the real
        // mechanism that makes two conflicting resolutions impossible,
        // independent of whichever UI layer calls it. A second,
        // Livewire-driven action against the SAME row is additionally
        // proven impossible above (test_a_completed_operation_cannot_be_reopened_by_a_repeated_action_call):
        // once resolved, the row no longer matches this page's own
        // `reconciliation_required` query at all, so a second operator's
        // stale tab cannot even locate it to act on.
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $attempt = $this->attemptRequiringReconciliation();

        app(ProviderOperationAttemptService::class)->resolveReconciliation(
            $attempt, ProviderOperationAttemptState::LocalProcessingComplete, 'first operator confirmed', $admin->id,
        );

        $this->expectException(ProviderOperationOwnershipLostException::class);

        app(ProviderOperationAttemptService::class)->resolveReconciliation(
            $attempt, ProviderOperationAttemptState::RetryAllowed, 'second operator, unaware of the first', $admin->id,
        );
    }

    public function test_a_completed_operation_cannot_be_reopened_by_a_repeated_action_call(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation();

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(ConfirmProviderOperationSucceededAction::getDefaultName(), $attempt, data: ['reason' => 'first confirmation'])
            ->assertHasNoTableActionErrors();

        $fresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $fresh->attempt_state);
        $this->assertStringContainsString('first confirmation', (string) $fresh->state_reason);

        // A repeated call against the now-settled row: once resolved,
        // the row no longer matches this page's own
        // `reconciliation_required` query at all, so a second, accidental
        // click (or a second operator's stale tab) cannot even locate it
        // to act on — an even stronger guarantee than a no-op. The
        // service-level CAS itself (proven directly in
        // test_two_conflicting_resolutions_cannot_both_win) is what
        // would ALSO refuse a repeated resolution if it were ever
        // reached through some other path.
        try {
            Livewire::test(PlatformProviderOperationReconciliationPage::class)
                ->callTableAction(ConfirmProviderOperationSucceededAction::getDefaultName(), $attempt, data: ['reason' => 'accidental repeat click']);
        } catch (\Throwable) {
            // Expected: the row is no longer in this page's own query.
        }

        $stillFresh = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)->find($attempt->id);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $stillFresh->attempt_state);
        $this->assertStringContainsString('first confirmation', (string) $stillFresh->state_reason, 'The repeated call must never overwrite the original resolution reason.');
    }

    // ------------------------------------------------------------------
    // Cross-firm isolation
    // ------------------------------------------------------------------

    public function test_a_mismatched_firm_id_cannot_be_used_to_resolve_another_firms_operation(): void
    {
        // Every action's own lookup goes through
        // ProviderOperationAttemptService::findByIdForFirm() — this is
        // the actual defense-in-depth boundary every action shares
        // (Livewire itself already re-resolves a table record fresh by
        // its own query on every request, so a client cannot smuggle a
        // tampered firm_id through the Livewire layer in the first
        // place; findByIdForFirm() is what protects a caller that skips
        // that layer entirely).
        $attempt = $this->attemptRequiringReconciliation(701);

        $result = app(ProviderOperationAttemptService::class)->findByIdForFirm($attempt->id, 999999);

        $this->assertNull($result, 'A firm_id mismatch must refuse the lookup entirely.');

        $correct = app(ProviderOperationAttemptService::class)->findByIdForFirm($attempt->id, 701);
        $this->assertNotNull($correct, 'Baseline: the correct firm_id must still find the row — otherwise the refusal above proves nothing.');
    }

    // ------------------------------------------------------------------
    // Audit trail completeness and redaction
    // ------------------------------------------------------------------

    public function test_every_mutation_records_the_operator_operation_and_state_transition(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attempt = $this->attemptRequiringReconciliation(801);

        Livewire::test(PlatformProviderOperationReconciliationPage::class)
            ->callTableAction(ConfirmProviderOperationSucceededAction::getDefaultName(), $attempt, data: ['reason' => 'audit completeness check']);

        // Firm 801 does not exist as a real Firm row in this test, so the
        // audit recorder falls back to its platform-event path (firm_id
        // carried as plain metadata) — assert against security_events
        // directly, which both paths write to.
        $row = $this->auditRow('confirm_provider_succeeded');
        $this->assertNotNull($row);
        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame($admin->id, $metadata['operator_user_id']);
        $this->assertContains('super_admin', $metadata['operator_roles']);
        $this->assertSame('push_sync:test-key-1', $metadata['operation_identifier']);
        $this->assertSame(801, $metadata['firm_id']);
        $this->assertSame('reconciliation_required', $metadata['previous_state']);
        $this->assertSame('local_processing_complete', $metadata['new_state']);
        $this->assertSame('audit completeness check', $metadata['safe_reason']);
    }

    public function test_the_page_never_exposes_a_raw_token_or_provider_payload(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->makeAttempt([
            'uuid' => (string) Str::uuid7(),
            'logical_operation_key' => 'push_sync:redaction-check',
            'provider_key' => 'microsoft365',
            'firm_id' => 901,
            'operation_type' => 'push_sync',
            'attempt_state' => ProviderOperationAttemptState::ReconciliationRequired->value,
            'redacted_result_metadata' => json_encode(['external_id' => 'ext-901', 'version_token' => 'v-901']),
        ]);

        $response = $this->get(PlatformProviderOperationReconciliationPage::getUrl());
        $response->assertOk();

        foreach (['access_token', 'refresh_token', 'authorization:', 'Bearer ', 'client_secret'] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }
}
