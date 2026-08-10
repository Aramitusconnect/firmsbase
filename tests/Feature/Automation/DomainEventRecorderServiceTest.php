<?php

namespace Tests\Feature\Automation;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\Automation\DomainEventRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DomainEventRecorderServiceTest — Event-Driven Automation Engine, item
 * 1 + item 12 (outbox/transaction safety) + item 17 (security). Proves
 * the payload allowlist is enforced, correlation/causation propagation
 * is correct for both organic and automation-caused events, and — the
 * core outbox guarantee — that a domain_events row inserted inside a
 * business transaction that later rolls back never actually exists.
 */
class DomainEventRecorderServiceTest extends TestCase
{
    use RefreshDatabase;

    private DomainEventRecorderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DomainEventRecorderService;
    }

    private function matterPayload(): array
    {
        return ['matter' => ['id' => 1, 'client_id' => 1, 'assigned_attorney_id' => null, 'status' => 'open']];
    }

    public function test_record_persists_the_expected_fields(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => $this->service->record(
            $firm, DomainEventType::MatterOpened, $this->matterPayload(),
        ));

        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame(DomainEventType::MatterOpened, $event->event_type);
        $this->assertSame($this->matterPayload(), $event->payload_json);
        $this->assertNotEmpty($event->correlation_id);
        $this->assertNull($event->causation_event_id);
        $this->assertSame(0, $event->causation_depth);
    }

    public function test_payload_field_not_on_the_allowlist_is_rejected(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->service->record(
            $firm, DomainEventType::MatterOpened,
            ['matter' => ['id' => 1, 'client_id' => 1, 'assigned_attorney_id' => null, 'status' => 'open', 'billing_rate_cents' => 50000]],
        ));
    }

    public function test_a_caused_by_event_inherits_correlation_id_and_increments_depth(): void
    {
        $firm = Firm::factory()->create();

        [$root, $child] = $this->runWithFirmContext($firm, function () use ($firm) {
            $root = $this->service->record($firm, DomainEventType::MatterOpened, $this->matterPayload());
            $child = $this->service->record(
                $firm, DomainEventType::MatterOpened, $this->matterPayload(), null, $root,
            );

            return [$root, $child];
        });

        $this->assertSame($root->correlation_id, $child->correlation_id);
        $this->assertSame($root->id, $child->causation_event_id);
        $this->assertSame(1, $child->causation_depth);
    }

    public function test_two_organically_triggered_events_get_independent_correlation_ids(): void
    {
        $firm = Firm::factory()->create();

        [$a, $b] = $this->runWithFirmContext($firm, fn () => [
            $this->service->record($firm, DomainEventType::MatterOpened, $this->matterPayload()),
            $this->service->record($firm, DomainEventType::MatterOpened, $this->matterPayload()),
        ]);

        $this->assertNotSame($a->correlation_id, $b->correlation_id);
    }

    public function test_event_recorded_inside_a_transaction_that_rolls_back_never_exists(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () use ($firm) {
                DB::transaction(function () use ($firm) {
                    $this->service->record($firm, DomainEventType::MatterOpened, $this->matterPayload());

                    throw new \RuntimeException('deliberate rollback of the business transaction');
                });
            });

            $this->fail('Expected the deliberate rollback exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('deliberate rollback of the business transaction', $e->getMessage());
        }

        $count = $this->runWithFirmContext($firm, fn () => DomainEvent::query()->count());
        $this->assertSame(0, $count);
    }
}
