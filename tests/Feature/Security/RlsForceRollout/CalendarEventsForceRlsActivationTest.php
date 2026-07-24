<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\CalendarEventType;
use App\Enums\DeadlineStatus;
use App\Models\CalendarEvent;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\CalendarEventService;
use App\Services\ComplianceGapRegistryService;
use App\Services\DeadlineService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CalendarEventsForceRlsActivationTest — Section 39A-3K (batch 4 of 5).
 * Proves the seventeenth staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php)
 * is permanently active for calendar_events and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that every table forced by a prior section or
 * by this same batch (clients, firm_users, documents, deadlines, tasks,
 * matters, invoices, payments, conflict_check_runs, lead_sources,
 * consultation_outcomes, firm_leads, consultations, firm_practice_areas,
 * document_chase_rules, employee_rates) remains forced simultaneously.
 *
 * This is the most important table in this batch: rls-force-implementer
 * found and fixed a REAL ownership-validation bug here —
 * CalendarEventService previously trusted whatever $firm the caller
 * passed with no cross-check against $matter/$subject's own firm_id.
 * This file proves the fix (assertBelongsToFirm()) actually throws on
 * a genuine mismatch, proves CalendarEventFactory's forMatter()/
 * forSubject() states derive firm_id correctly, and proves
 * DeadlineService's one production integration with
 * CalendarEventService still works end to end.
 */
class CalendarEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_thirteen_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $previouslyForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
            'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        ];

        foreach ($previouslyForced as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE RLS enabled after this batch.");
        }
    }

    public function test_firm_practice_areas_document_chase_rules_and_employee_rates_are_also_force_row_level_security_enabled(): void
    {
        foreach (['firm_practice_areas', 'document_chase_rules', 'employee_rates'] as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row);
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must be FORCE RLS enabled alongside calendar_events in this batch.");
        }
    }

    public function test_calendar_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'calendar_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_calendar_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'calendar_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'calendar_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_calendar_events(): void
    {
        $firm = Firm::factory()->create();
        CalendarEvent::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, CalendarEvent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_calendar_events(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('calendar_events')->insert([
            'firm_id' => $firm->id,
            'event_type' => CalendarEventType::Standalone->value,
            'title' => 'No Context Insert',
            'starts_at' => now()->addDay(),
            'all_day' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_calendar_events(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => CalendarEvent::factory()->create(['firm_id' => $firmA->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => CalendarEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_calendar_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => CalendarEvent::factory()->create(['firm_id' => $firmA->id]));
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => CalendarEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_calendar_event(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('calendar_events')->insertGetId([
                'firm_id' => $firmA->id,
                'event_type' => CalendarEventType::Standalone->value,
                'title' => 'Valid Event',
                'starts_at' => now()->addDay(),
                'all_day' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_calendar_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id, 'title' => 'Original Title']));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('calendar_events')->where('id', $eventB->id)->update(['title' => 'Hijacked Title']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CalendarEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Title', $reReadAsFirmB->title);
    }

    public function test_firm_a_cannot_delete_firm_b_calendar_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id]));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('calendar_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CalendarEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B calendar_events.');
    }

    public function test_firm_a_cannot_insert_a_calendar_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('calendar_events')->insert([
                'firm_id' => $firmB->id,
                'event_type' => CalendarEventType::Standalone->value,
                'title' => 'Cross-Firm Insert Attempt',
                'starts_at' => now()->addDay(),
                'all_day' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => CalendarEvent::factory()->create(['firm_id' => $firmA->id]));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($eventA, $firmB) {
            DB::table('calendar_events')->where('id', $eventA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => CalendarEvent::factory()->create(['firm_id' => $firm->id]));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * The context-hold create() pattern: a bare
     * CalendarEvent::factory()->create() (no explicit matter/subject —
     * definition() itself never resolves either) must still succeed and
     * be immediately readable.
     */
    public function test_default_factory_creation_is_safe_and_immediately_readable(): void
    {
        $event = CalendarEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNull($event->matter_id);
        $this->assertNull($event->subject_type);

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $event->firm_id,
            fn () => CalendarEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'A bare CalendarEvent::factory()->create() must be readable under its own firm context.');
    }

    /**
     * CalendarEventFactory::forMatter() (new in this batch) must derive
     * firm_id FROM the matter, never leaving definition()'s
     * independently-resolved Firm::factory() value in place.
     */
    public function test_factory_for_matter_state_derives_firm_id_from_the_matter(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $event = $this->runWithFirmContext($firm, fn () => CalendarEvent::factory()->forMatter($matter)->create());

        $this->assertSame($matter->firm_id, $event->firm_id, 'forMatter() must derive firm_id from the given matter, not an independently-resolved firm.');
        $this->assertSame($matter->id, $event->matter_id);
    }

    /**
     * CalendarEventFactory::forSubject() (fixed in this batch to accept
     * a real subject Model instead of a bare type/id pair) must derive
     * firm_id FROM the subject.
     */
    public function test_factory_for_subject_state_derives_firm_id_from_the_subject(): void
    {
        $firm = Firm::factory()->create();
        $deadline = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id]));

        $event = $this->runWithFirmContext(
            $firm,
            fn () => CalendarEvent::factory()->forSubject($deadline, CalendarEventType::Deadline)->create(),
        );

        $this->assertSame($deadline->firm_id, $event->firm_id, 'forSubject() must derive firm_id from the given subject, not an independently-resolved firm.');
        $this->assertSame(Deadline::class, $event->subject_type);
        $this->assertSame($deadline->id, $event->subject_id);
        $this->assertSame(CalendarEventType::Deadline, $event->event_type);
    }

    /**
     * Core ownership-validation fix, proof 1: CalendarEventService::
     * createFor() must throw when the given $matter belongs to a
     * DIFFERENT firm than $firm — rather than silently writing a
     * cross-firm-mismatched row.
     */
    public function test_create_for_throws_when_matter_belongs_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $deadlineA = $this->runWithFirmContext($firmA, fn () => Deadline::factory()->create(['firm_id' => $firmA->id]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/matter belongs to firm/');

        $this->runWithFirmContext($firmA, function () use ($firmA, $deadlineA, $matterB) {
            (new CalendarEventService())->createFor(
                $firmA,
                $deadlineA,
                CalendarEventType::Deadline,
                'Mismatched matter',
                now()->addDay(),
                matter: $matterB,
            );
        });
    }

    /**
     * Core ownership-validation fix, proof 2: createFor() must throw
     * when the given polymorphic $subject belongs to a DIFFERENT firm
     * than $firm.
     */
    public function test_create_for_throws_when_subject_belongs_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deadlineB = $this->runWithFirmContext($firmB, fn () => Deadline::factory()->create(['firm_id' => $firmB->id]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/subject belongs to firm/');

        $this->runWithFirmContext($firmA, function () use ($firmA, $deadlineB) {
            (new CalendarEventService())->createFor(
                $firmA,
                $deadlineB,
                CalendarEventType::Deadline,
                'Mismatched subject',
                now()->addDay(),
            );
        });
    }

    /**
     * Core ownership-validation fix, proof 3: createStandalone() must
     * ALSO throw when given a mismatched $matter (it has no subject
     * parameter, so only the matter check applies).
     */
    public function test_create_standalone_throws_when_matter_belongs_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/matter belongs to firm/');

        $this->runWithFirmContext($firmA, function () use ($firmA, $matterB) {
            (new CalendarEventService())->createStandalone(
                $firmA,
                'Mismatched matter, standalone',
                now()->addDay(),
                matter: $matterB,
            );
        });
    }

    /**
     * The guard must not falsely reject a genuinely correctly-owned
     * matter and subject — a same-firm createFor() call must still
     * succeed under FORCE.
     */
    public function test_create_for_succeeds_when_matter_and_subject_belong_to_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $deadline = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id]));

        $event = $this->runWithFirmContext($firm, function () use ($firm, $deadline, $matter) {
            return (new CalendarEventService())->createFor(
                $firm,
                $deadline,
                CalendarEventType::Deadline,
                'Consistent matter and subject',
                now()->addDay(),
                matter: $matter,
            );
        });

        $this->assertNotNull($event->id);
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($matter->id, $event->matter_id);
    }

    /**
     * DeadlineService's one production integration with
     * CalendarEventService (DeadlineService::create() ->
     * CalendarEventService::createFor()) must still work end to end
     * under FORCE — this is the one production caller and must not
     * regress.
     */
    public function test_deadline_service_integration_with_calendar_event_service_still_works_end_to_end(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $deadline = (new DeadlineService(new CalendarEventService()))->create(
            $firm,
            'Response deadline',
            'response_deadline',
            now()->addDays(30),
            matter: $matter,
            reminderOffsetsDays: [7, 3, 1],
        );

        $this->assertSame(DeadlineStatus::Upcoming, $deadline->status);

        $calendarEvent = $this->runWithFirmContext(
            $firm,
            fn () => CalendarEvent::withoutGlobalScopes()->where('subject_id', $deadline->id)->where('subject_type', Deadline::class)->first(),
        );

        $this->assertNotNull($calendarEvent, 'DeadlineService::create() must still produce a linked calendar_events row under FORCE.');
        $this->assertSame($firm->id, $calendarEvent->firm_id);
        $this->assertSame($matter->id, $calendarEvent->matter_id);
        $this->assertSame(CalendarEventType::Deadline, $calendarEvent->event_type);
    }

    /**
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'calendar_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        foreach ($coverage->missingPreparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this batch must not add new policies for uncovered tables."
            );
        }
    }

    public function test_no_other_policy_was_changed(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'calendar_events'::regclass and polname = 'calendar_events_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original calendar_events_tenant_isolation policy must still exist.');
        $this->assertSame(
            "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)",
            $row->using_expr,
            'The existing policy USING expression must be unchanged by this batch.'
        );
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this batch.');
    }

    public function test_rls_prepared_not_enforced_remains_tracked(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
