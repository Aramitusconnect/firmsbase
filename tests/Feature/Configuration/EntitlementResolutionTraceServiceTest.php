<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Services\Configuration\EntitlementResolutionTraceService;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the resolution trace explains the CANONICAL resolver's answer
 * rather than computing its own (mission sections 40/42/99): in every
 * scenario the trace's effective state and winning source must equal
 * what EntitlementService::resolve() independently returns.
 */
class EntitlementResolutionTraceServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementResolutionTraceService $trace;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trace = app(EntitlementResolutionTraceService::class);
        $this->entitlements = app(EntitlementService::class);
    }

    private function entitle(Firm $firm, string $module, EntitlementSource $source, bool $enabled, ?string $startsAt = null, ?string $endsAt = null): FirmEntitlement
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn (): FirmEntitlement => FirmEntitlement::create([
            'firm_id' => $firm->id,
            'module_code' => $module,
            'enabled' => $enabled,
            'source' => $source,
            'settings_json' => [],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));
    }

    public function test_sources_are_listed_in_canonical_precedence_order(): void
    {
        $order = array_map(
            fn (EntitlementSource $s): string => $s->value,
            EntitlementResolutionTraceService::sourcesByPrecedenceDesc(),
        );

        $this->assertSame(['admin_override', 'firm_override', 'org_inherited', 'plan'], $order);
    }

    public function test_a_firm_with_no_records_traces_to_not_entitled(): void
    {
        $firm = Firm::factory()->create();

        $trace = $this->trace->trace($firm, 'time_tracking');

        $this->assertFalse($trace['effective_enabled']);
        $this->assertSame('Not entitled', $trace['effective_label']);
        $this->assertNull($trace['winning_source']);
        $this->assertFalse($trace['has_any_record']);

        // Every source line must say "not configured" — never a
        // misleading "Disabled" (mission section 24).
        foreach ($trace['rows'] as $row) {
            $this->assertSame('Not configured', $row['configured_state']);
            $this->assertFalse($row['present']);
        }
    }

    public function test_a_plan_only_entitlement_wins_and_is_labelled_as_the_plan(): void
    {
        $firm = Firm::factory()->create();
        $this->entitle($firm, 'time_tracking', EntitlementSource::Plan, true);

        $trace = $this->trace->trace($firm, 'time_tracking');

        $this->assertTrue($trace['effective_enabled']);
        $this->assertSame(EntitlementSource::Plan, $trace['winning_source']);

        $planRow = collect($trace['rows'])->firstWhere('source', EntitlementSource::Plan);
        $this->assertTrue($planRow['is_winner']);
        $this->assertNull($planRow['why_not_winner']);
    }

    public function test_a_firm_override_outranks_the_plan_and_the_trace_says_why(): void
    {
        $firm = Firm::factory()->create();
        $this->entitle($firm, 'time_tracking', EntitlementSource::Plan, true);
        $this->entitle($firm, 'time_tracking', EntitlementSource::FirmOverride, false);

        $trace = $this->trace->trace($firm, 'time_tracking');

        $this->assertFalse($trace['effective_enabled']);
        $this->assertSame(EntitlementSource::FirmOverride, $trace['winning_source']);

        $planRow = collect($trace['rows'])->firstWhere('source', EntitlementSource::Plan);
        $this->assertFalse($planRow['is_winner']);
        $this->assertStringContainsString('Outranked by Firm override', $planRow['why_not_winner']);
        // The plan's own CONFIGURED state is still reported honestly.
        $this->assertSame('Enabled', $planRow['configured_state']);
    }

    public function test_an_admin_override_outranks_a_firm_override(): void
    {
        $firm = Firm::factory()->create();
        $this->entitle($firm, 'time_tracking', EntitlementSource::FirmOverride, false);
        $this->entitle($firm, 'time_tracking', EntitlementSource::AdminOverride, true);

        $trace = $this->trace->trace($firm, 'time_tracking');

        $this->assertTrue($trace['effective_enabled']);
        $this->assertSame(EntitlementSource::AdminOverride, $trace['winning_source']);
    }

    public function test_an_expired_override_does_not_win_and_is_labelled_expired(): void
    {
        $firm = Firm::factory()->create();
        $this->entitle($firm, 'time_tracking', EntitlementSource::Plan, true);
        $this->entitle($firm, 'time_tracking', EntitlementSource::FirmOverride, false, endsAt: now()->subDay()->toDateTimeString());

        $trace = $this->trace->trace($firm, 'time_tracking');

        // The expired override must NOT remain effective (section 48).
        $this->assertTrue($trace['effective_enabled']);
        $this->assertSame(EntitlementSource::Plan, $trace['winning_source']);

        $overrideRow = collect($trace['rows'])->firstWhere('source', EntitlementSource::FirmOverride);
        $this->assertSame('Expired', $overrideRow['window_state']);
        $this->assertStringContainsString('already expired', $overrideRow['why_not_winner']);
    }

    public function test_a_future_dated_override_is_not_yet_effective_and_says_so(): void
    {
        $firm = Firm::factory()->create();
        $this->entitle($firm, 'time_tracking', EntitlementSource::Plan, true);
        $this->entitle($firm, 'time_tracking', EntitlementSource::FirmOverride, false, startsAt: now()->addWeek()->toDateTimeString());

        $trace = $this->trace->trace($firm, 'time_tracking');

        $this->assertTrue($trace['effective_enabled'], 'a future-dated override must not apply early');

        $overrideRow = collect($trace['rows'])->firstWhere('source', EntitlementSource::FirmOverride);
        $this->assertSame('Scheduled — not yet in effect', $overrideRow['window_state']);
        $this->assertStringContainsString('starts in the future', $overrideRow['why_not_winner']);
    }

    public function test_a_permanent_override_is_labelled_as_having_no_end_date(): void
    {
        $firm = Firm::factory()->create();
        $this->entitle($firm, 'time_tracking', EntitlementSource::AdminOverride, true);

        $trace = $this->trace->trace($firm, 'time_tracking');
        $row = collect($trace['rows'])->firstWhere('source', EntitlementSource::AdminOverride);

        $this->assertSame('In effect — no end date', $row['window_state']);
        $this->assertNull($row['ends_at']);
    }

    /**
     * The anti-drift guarantee: the trace must never disagree with the
     * canonical resolver, in any combination.
     */
    public function test_the_trace_always_agrees_with_the_canonical_resolver(): void
    {
        $scenarios = [
            [[EntitlementSource::Plan, true]],
            [[EntitlementSource::Plan, false], [EntitlementSource::FirmOverride, true]],
            [[EntitlementSource::Plan, true], [EntitlementSource::FirmOverride, false], [EntitlementSource::AdminOverride, true]],
            [[EntitlementSource::OrgInherited, true], [EntitlementSource::Plan, false]],
            [[EntitlementSource::AdminOverride, false], [EntitlementSource::Plan, true]],
        ];

        foreach ($scenarios as $index => $records) {
            $firm = Firm::factory()->create();
            $module = 'module_scenario_'.$index;

            // firm_entitlements.module_code is a real FK to
            // module_catalog, so each scenario needs a catalogued module.
            ModuleCatalog::query()->create([
                'module_code' => $module,
                'module_name' => 'Scenario Module '.$index,
                'category' => 'core',
                'is_active' => true,
            ]);

            foreach ($records as [$source, $enabled]) {
                $this->entitle($firm, $module, $source, $enabled);
            }

            $canonical = $this->entitlements->resolve($firm->id, $module);
            $trace = $this->trace->trace($firm, $module);

            $this->assertSame(
                $canonical->enabled,
                $trace['effective_enabled'],
                "scenario {$index}: trace disagreed with the canonical resolver on effective state",
            );
            $this->assertSame(
                $canonical->source,
                $trace['winning_source'],
                "scenario {$index}: trace disagreed with the canonical resolver on winning source",
            );
        }
    }

    public function test_module_names_come_from_the_canonical_catalog(): void
    {
        ModuleCatalog::query()->create([
            'module_code' => 'zzz_time_tracking',
            'module_name' => 'Zzz Time Tracking',
            'category' => 'core',
            'is_active' => true,
        ]);

        $this->assertSame('Zzz Time Tracking', $this->trace->moduleName('zzz_time_tracking'));
    }

    public function test_an_uncatalogued_module_code_is_humanized_rather_than_shown_raw(): void
    {
        $this->assertSame('Zzz Unlisted Module', $this->trace->moduleName('zzz_unlisted_module'));
    }

    public function test_override_sources_are_flagged_distinctly_from_derived_sources(): void
    {
        $firm = Firm::factory()->create();

        $rows = collect($this->trace->trace($firm, 'time_tracking')['rows'])->keyBy(
            fn (array $row): string => $row['source']->value,
        );

        $this->assertTrue($rows['admin_override']['is_override']);
        $this->assertTrue($rows['firm_override']['is_override']);
        $this->assertFalse($rows['plan']['is_override'], 'plan-derived rows must never be presented as overrides');
        $this->assertFalse($rows['org_inherited']['is_override']);
    }
}
