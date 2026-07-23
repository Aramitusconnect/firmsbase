<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\FirmUserRole;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TimelineEvent;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationAccessPolicyServiceTest — Checkpoint 9 (frozen design §10
 * item 1). Closes a 3-checkpoint-old test-coverage gap: no standalone
 * test file for IntegrationAccessPolicyService has existed since
 * Checkpoint 3. Full 6-role-sweep table-driven proof for every
 * can*()/assertCan*() pair (including the new canSync()/assertCanSync()),
 * a Receptionist-never-passes-any-check regression, and proof that
 * integration_governance.action_denied fires on every denial with
 * correct metadata.
 */
class IntegrationAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationAccessPolicyService $service;

    /** @var array<int, FirmUserRole> all 6 roles, for the table-driven sweep */
    private const ALL_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationAccessPolicyService(new TimelineEventRecorder());
    }

    /**
     * @return array<int, array{0: string, 1: array<int, FirmUserRole>}>
     */
    public static function canMethodProvider(): array
    {
        return [
            'canView' => ['canView', [FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant]],
            'canConnect' => ['canConnect', [FirmUserRole::FirmOwner, FirmUserRole::Attorney]],
            'canConfigure' => ['canConfigure', [FirmUserRole::FirmOwner, FirmUserRole::Attorney]],
            'canDisconnect' => ['canDisconnect', [FirmUserRole::FirmOwner, FirmUserRole::Attorney]],
            'canViewUsage' => ['canViewUsage', [FirmUserRole::FirmOwner, FirmUserRole::BillingStaff]],
            'canSync' => ['canSync', [FirmUserRole::FirmOwner, FirmUserRole::Attorney]],
        ];
    }

    /**
     * @param  array<int, FirmUserRole>  $allowedRoles
     */
    #[DataProvider('canMethodProvider')]
    public function test_can_method_allows_exactly_the_expected_roles_and_denies_every_other_role(string $method, array $allowedRoles): void
    {
        foreach (self::ALL_ROLES as $role) {
            $expected = in_array($role, $allowedRoles, true);
            $this->assertSame(
                $expected,
                $this->service->{$method}($role),
                "{$method}({$role->value}) expected ".($expected ? 'true' : 'false')
            );
        }
    }

    /**
     * @return array<int, array{0: string, 1: array<int, FirmUserRole>, 2: string}>
     */
    public static function assertCanMethodProvider(): array
    {
        return [
            'assertCanView' => ['assertCanView', [FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant], 'view'],
            'assertCanConnect' => ['assertCanConnect', [FirmUserRole::FirmOwner, FirmUserRole::Attorney], 'connect'],
            'assertCanConfigure' => ['assertCanConfigure', [FirmUserRole::FirmOwner, FirmUserRole::Attorney], 'configure'],
            'assertCanDisconnect' => ['assertCanDisconnect', [FirmUserRole::FirmOwner, FirmUserRole::Attorney], 'disconnect'],
            'assertCanViewUsage' => ['assertCanViewUsage', [FirmUserRole::FirmOwner, FirmUserRole::BillingStaff], 'view_usage'],
            'assertCanSync' => ['assertCanSync', [FirmUserRole::FirmOwner, FirmUserRole::Attorney], 'sync'],
        ];
    }

    /**
     * @param  array<int, FirmUserRole>  $allowedRoles
     */
    #[DataProvider('assertCanMethodProvider')]
    public function test_assert_can_method_is_a_noop_for_allowed_roles_and_throws_for_every_other_role(string $method, array $allowedRoles, string $expectedAction): void
    {
        foreach (self::ALL_ROLES as $role) {
            if (in_array($role, $allowedRoles, true)) {
                $firm = \App\Models\Firm::factory()->create();
                $actor = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);

                $this->runWithFirmContext($firm, fn () => $this->service->{$method}($actor));
                $this->addToAssertionCount(1); // no exception == pass
                continue;
            }

            // Denial path: recordDenied() writes
            // integration_governance.action_denied on the separate
            // 'pgsql_audit' connection (TimelineEventRecorder::
            // recordOnIndependentConnection()) precisely so the row
            // survives this test's own RefreshDatabase rollback. That
            // write can only see a Firm row that is genuinely committed
            // in another database session — a Firm created on the
            // default, RefreshDatabase-wrapped connection is never
            // committed for the whole duration of this test, so it must
            // be created for real via Firm::factory()->connection('pgsql_audit')
            // instead (a real, immediate commit, visible from every
            // session per Postgres's READ COMMITTED isolation).
            $firm = Firm::factory()->connection('pgsql_audit')->create();
            $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

            $actor = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);

            $this->runWithFirmContext($firm, function () use ($actor, $method, $expectedAction, $firm) {
                $threw = false;
                try {
                    $this->service->{$method}($actor);
                } catch (RuntimeException $e) {
                    $threw = true;
                }
                $this->assertTrue($threw, "{$method}() must throw for role {$actor->role->value}");

                $event = TimelineEvent::query()
                    ->where('event_type', 'integration_governance.action_denied')
                    ->where('actor_id', $actor->user_id)
                    ->latest('id')
                    ->first();

                $this->assertNotNull($event, "{$method}() denial must fire integration_governance.action_denied");
                $this->assertSame($expectedAction, $event->metadata_json['action']);
                $this->assertSame($actor->role->value, $event->metadata_json['role']);
                $this->assertSame(IntegrationAccessPolicyService::class, $event->metadata_json['policy_service']);
            });
        }
    }

    /**
     * Registers cleanup for a Firm (and any timeline_events rows written
     * against it on the independent 'pgsql_audit' connection) that was
     * created via Firm::factory()->connection('pgsql_audit')->create()
     * to make a denial's durable audit write visible across sessions.
     * Neither row is touched by RefreshDatabase's automatic rollback
     * (that trait only wraps the default 'pgsql' connection), so
     * without this, repeated test runs against the same database would
     * accumulate garbage firms/timeline_events rows indefinitely.
     *
     * MUST run via beforeApplicationDestroyed(), not an inline
     * try/finally in the test body: every FirmUser (and, in other
     * tests, FirmIntegration) created against this Firm on the default
     * connection while RefreshDatabase's own outer transaction is still
     * open holds a Postgres FOR KEY SHARE lock on this Firm row for the
     * FK reference, for the whole remaining life of that transaction —
     * attempting to DELETE the Firm from the separate 'pgsql_audit'
     * session before that transaction rolls back deadlocks (reproduced
     * directly: the cleanup query blocks forever waiting on a
     * `Lock/transactionid` wait event). Registering via
     * beforeApplicationDestroyed() defers this cleanup until AFTER
     * RefreshDatabase's own rollback callback has already run (Laravel
     * invokes these callbacks in registration order, and
     * RefreshDatabase registers its rollback in setUp(), before the
     * test body runs) but before the application container is flushed,
     * so the FK lock is already released and Eloquent/DB facades are
     * still usable.
     *
     * timeline_events has permanent FORCE ROW LEVEL SECURITY, so the
     * DELETE must run with app.current_firm_id set to this firm's id on
     * the SAME 'pgsql_audit' connection performing it — mirrors
     * TimelineEventRecorder::recordOnIndependentConnection()'s own
     * SET LOCAL pattern exactly. firms itself carries no RLS policy, so
     * no context is needed for the second delete. firm_id is
     * nullOnDelete() on timeline_events, so deleting timeline_events
     * rows before the firm avoids leaving them as invisible orphans
     * instead of genuinely removed.
     */
    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
    }

    // ------------------------------------------------------------
    // Receptionist regression: NEVER passes any check, full stop.
    // ------------------------------------------------------------

    public function test_receptionist_never_passes_any_can_check(): void
    {
        $this->assertFalse($this->service->canView(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canConnect(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canConfigure(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canDisconnect(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canViewUsage(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canSync(FirmUserRole::Receptionist));
    }

    public function test_receptionist_never_passes_any_assert_can_check(): void
    {
        $firm = \App\Models\Firm::factory()->create();
        $receptionist = FirmUser::factory()->role(FirmUserRole::Receptionist)->create(['firm_id' => $firm->id]);

        foreach (['assertCanView', 'assertCanConnect', 'assertCanConfigure', 'assertCanDisconnect', 'assertCanViewUsage', 'assertCanSync'] as $method) {
            $this->runWithFirmContext($firm, function () use ($receptionist, $method) {
                $this->expectException(RuntimeException::class);
                $this->service->{$method}($receptionist);
            });
        }
    }

    // ------------------------------------------------------------
    // Direct sweep for assertCanSync()/canSync() (new this checkpoint)
    // ------------------------------------------------------------

    public function test_can_sync_matches_the_management_tier_ceiling_exactly(): void
    {
        $this->assertTrue($this->service->canSync(FirmUserRole::FirmOwner));
        $this->assertTrue($this->service->canSync(FirmUserRole::Attorney));
        $this->assertFalse($this->service->canSync(FirmUserRole::Paralegal));
        $this->assertFalse($this->service->canSync(FirmUserRole::LegalAssistant));
        $this->assertFalse($this->service->canSync(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canSync(FirmUserRole::BillingStaff));
    }

    public function test_assert_can_sync_denial_fires_action_denied_with_sync_action(): void
    {
        // Durable Firm required — see the cleanUpDurableFirmAuditTrailAfterRollback()
        // docblock above: assertCanSync()'s denial writes
        // integration_governance.action_denied on the independent
        // 'pgsql_audit' connection, which cannot see a Firm still
        // uncommitted inside this test's RefreshDatabase transaction.
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        $billingStaff = FirmUser::factory()->role(FirmUserRole::BillingStaff)->create(['firm_id' => $firm->id]);

        $this->runWithFirmContext($firm, function () use ($billingStaff) {
            try {
                $this->service->assertCanSync($billingStaff);
                $this->fail('expected RuntimeException');
            } catch (RuntimeException $e) {
                // expected
            }

            $event = TimelineEvent::query()->where('event_type', 'integration_governance.action_denied')->latest('id')->first();
            $this->assertNotNull($event);
            $this->assertSame('sync', $event->metadata_json['action']);
        });
    }
}
