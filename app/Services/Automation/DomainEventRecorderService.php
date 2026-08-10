<?php

namespace App\Services\Automation;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * DomainEventRecorderService — Event-Driven Automation Engine, item 1.
 * The ONLY writer of domain_events. See DomainEvent's own docblock and
 * the create-table migration for the full "this IS the transactional
 * outbox" rationale.
 *
 * Deliberately does NOT open its own transaction and does NOT establish
 * tenant context itself — every real call site invokes record() from
 * INSIDE its own already-active runWithFirmContext()+DB::transaction()
 * (mirrors TimelineEventRecorder::record()'s own simple-path
 * convention exactly), so this insert lands in the SAME database
 * transaction as the business mutation that caused it. If that
 * transaction rolls back, this row never existed — automation can never
 * run against a fact that didn't actually happen.
 *
 * $payload is validated against AutomationFieldAllowlistRegistry's own
 * per-event-type field list — every flattened dot-path key must be
 * pre-approved, or this throws. Never a runtime/config decision: adding
 * a new field to an event's payload is a reviewed code change to the
 * registry, not something a caller can silently introduce.
 */
class DomainEventRecorderService
{
    /**
     * @param  array<string, mixed>  $payload  nested associative array;
     *                                         flattened via dot-notation
     *                                         for allowlist validation
     * @param  DomainEvent|null  $causedBy  when this event was itself
     *                                      produced by an automation
     *                                      action executing (not used
     *                                      by any call site in this
     *                                      pass — every starter's
     *                                      emission is organic — but a
     *                                      real, tested mechanism for
     *                                      whenever one is), inherits
     *                                      its correlation_id and
     *                                      increments causation_depth;
     *                                      null means this is an
     *                                      organically-triggered event
     *                                      (fresh correlation_id,
     *                                      depth 0).
     */
    public function record(
        Firm $firm,
        DomainEventType $type,
        array $payload,
        ?Model $subject = null,
        ?DomainEvent $causedBy = null,
    ): DomainEvent {
        $this->assertPayloadAllowed($type, $payload);

        return DomainEvent::create([
            'firm_id' => $firm->id,
            'event_type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'correlation_id' => $causedBy?->correlation_id ?? (string) Str::uuid(),
            'causation_event_id' => $causedBy?->id,
            'causation_depth' => $causedBy !== null ? $causedBy->causation_depth + 1 : 0,
            'payload_json' => $payload,
        ]);
    }

    private function assertPayloadAllowed(DomainEventType $type, array $payload): void
    {
        $allowed = AutomationFieldAllowlistRegistry::allowedFields($type);
        $actual = array_keys(Arr::dot($payload));
        $disallowed = array_diff($actual, $allowed);

        if (! empty($disallowed)) {
            throw new \InvalidArgumentException(
                "DomainEventType::{$type->name} payload contains field(s) not on its allowlist: ".implode(', ', $disallowed).
                '. Add them to AutomationFieldAllowlistRegistry first if they are genuinely needed.'
            );
        }
    }
}
