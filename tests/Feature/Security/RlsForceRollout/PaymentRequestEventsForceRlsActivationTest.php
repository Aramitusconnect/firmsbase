<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\PaymentRequestEventType;
use App\Models\Firm;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PaymentRequestEventsForceRlsActivationTest — Payment Link / QR
 * Routing phase. Mirrors AccountingPeriodEventsForceRlsActivationTest/
 * PaymentAllocationsForceRlsActivationTest exactly: append-only
 * immutable audit trail, FORCE RLS from the same migration that
 * created the table, no self-lookup carve-out of its own (write-time
 * firm context is already established by then — see
 * PaymentRequestRlsTest::test_reading_events_alone_grants_no_access_to_the_payment_requests_table()).
 */
class PaymentRequestEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_request_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_request_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_payment_request_events(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firm->id,
            'payment_request_id' => $paymentRequest->id,
            'event_type' => PaymentRequestEventType::Created,
        ]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PaymentRequestEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_request_events(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_request_events')->insert([
            'firm_id' => $firm->id,
            'payment_request_id' => $paymentRequest->id,
            'event_type' => 'created',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_request_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => PaymentRequest::factory()->forFirm($firmA)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => PaymentRequest::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firmA->id, 'payment_request_id' => $requestA->id, 'event_type' => PaymentRequestEventType::Created,
        ]));
        $eventB = $this->runWithFirmContext($firmB, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firmB->id, 'payment_request_id' => $requestB->id, 'event_type' => PaymentRequestEventType::Created,
        ]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentRequestEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_an_existing_event_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());
        $event = $this->runWithFirmContext($firm, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firm->id, 'payment_request_id' => $paymentRequest->id, 'event_type' => PaymentRequestEventType::Created,
        ]));

        $this->runWithFirmContext($firm, function () use ($event) {
            $this->expectException(\LogicException::class);
            $event->update(['note' => 'tampered']);
        });
    }

    public function test_an_existing_event_can_never_be_deleted_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create());
        $event = $this->runWithFirmContext($firm, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firm->id, 'payment_request_id' => $paymentRequest->id, 'event_type' => PaymentRequestEventType::Created,
        ]));

        $this->runWithFirmContext($firm, function () use ($event) {
            $this->expectException(\LogicException::class);
            $event->delete();
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_01_100005_prepare_row_level_security_and_force_rls_on_payment_request_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_request_events'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
