<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\MarketplaceIntakeEventType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mission 3, checkpoint 1 — RLS proofs for marketplace_intakes,
 * mirroring PaymentRequestRlsTest exactly: FORCE RLS tenant isolation
 * plus the ADDITIONAL, narrow marketplace_intakes_self_lookup policy
 * that lets an unauthenticated public prospect read only the one row
 * their own opaque uuid names.
 */
class MarketplaceIntakeRlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_marketplace_intakes_with_no_context_returns_nothing(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        $rows = DB::table('marketplace_intakes')->count();

        $this->assertSame(0, $rows, 'With absolutely no context active, marketplace_intakes must return zero rows.');
    }

    public function test_firm_context_cannot_see_another_firms_marketplace_intakes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => MarketplaceIntake::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmB, fn () => MarketplaceIntake::factory()->forFirm($firmB)->create());

        $visibleToA = $this->runWithFirmContext($firmA, fn () => MarketplaceIntake::query()->count());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => MarketplaceIntake::query()->count());

        $this->assertSame(1, $visibleToA);
        $this->assertSame(1, $visibleToB);
    }

    public function test_self_lookup_context_alone_can_read_only_that_intakes_own_row(): void
    {
        $firm = Firm::factory()->create();
        $intakeOne = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());
        $intakeTwo = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $visibleUuids = $tenantContext->withMarketplaceIntakeSelfLookupContext(
            $intakeOne->uuid,
            fn () => DB::table('marketplace_intakes')->pluck('uuid')->all(),
        );

        $this->assertContains($intakeOne->uuid, $visibleUuids);
        $this->assertNotContains($intakeTwo->uuid, $visibleUuids, "A marketplace-intake self-lookup session must never reveal another intake's row.");
    }

    public function test_self_lookup_with_an_unknown_uuid_reveals_nothing(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        $visibleCount = (new TenantContextService)->withMarketplaceIntakeSelfLookupContext(
            (string) Str::uuid7(),
            fn () => DB::table('marketplace_intakes')->count(),
        );

        $this->assertSame(0, $visibleCount);
    }

    public function test_self_lookup_context_alone_cannot_insert_a_marketplace_intakes_row(): void
    {
        $firm = Firm::factory()->create();
        $existing = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $tenantContext->withMarketplaceIntakeSelfLookupContext($existing->uuid, function () use ($firm) {
            DB::table('marketplace_intakes')->insert([
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firm->id,
                'status' => 'started',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_self_lookup_context_alone_cannot_update_a_marketplace_intakes_row(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create(['prospect_name' => 'Original Name']));

        $tenantContext = new TenantContextService;

        $affected = $tenantContext->withMarketplaceIntakeSelfLookupContext(
            $intake->uuid,
            fn () => DB::table('marketplace_intakes')->where('id', $intake->id)->update(['status' => 'accepted']),
        );

        $this->assertSame(0, $affected, 'Self-lookup context alone must never be able to write to marketplace_intakes — it is a FOR SELECT-only policy.');

        $reRead = $this->runWithFirmContext($firm, fn () => DB::table('marketplace_intakes')->where('id', $intake->id)->value('status'));
        $this->assertSame('started', $reRead);
    }

    public function test_self_lookup_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        (new TenantContextService)->withMarketplaceIntakeSelfLookupContext($intake->uuid, fn () => 'ok');

        $value = DB::selectOne("select current_setting('app.current_marketplace_intake_uuid', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_marketplace_intake_uuid must be cleared after a successful call.');
    }

    public function test_self_lookup_context_clears_even_after_an_exception(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        try {
            (new TenantContextService)->withMarketplaceIntakeSelfLookupContext($intake->uuid, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $value = DB::selectOne("select current_setting('app.current_marketplace_intake_uuid', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_marketplace_intake_uuid must be cleared even when the callback throws.');
    }

    public function test_reading_events_alone_grants_no_access_to_the_marketplace_intakes_table(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $intakeA = $this->runWithFirmContext($firmA, fn () => MarketplaceIntake::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmA, fn () => MarketplaceIntakeEvent::factory()->create([
            'firm_id' => $firmA->id,
            'marketplace_intake_id' => $intakeA->id,
            'event_type' => MarketplaceIntakeEventType::Started,
        ]));

        $visibleToB = $this->runWithFirmContext($firmB, fn () => DB::table('marketplace_intake_events')->where('marketplace_intake_id', $intakeA->id)->count());

        $this->assertSame(0, $visibleToB);
    }

    public function test_marketplace_intake_events_is_append_only(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());
        $event = $this->runWithFirmContext($firm, fn () => MarketplaceIntakeEvent::factory()->create([
            'firm_id' => $firm->id,
            'marketplace_intake_id' => $intake->id,
            'event_type' => MarketplaceIntakeEventType::Started,
        ]));

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $event->update(['event_type' => MarketplaceIntakeEventType::Submitted]));
    }
}
