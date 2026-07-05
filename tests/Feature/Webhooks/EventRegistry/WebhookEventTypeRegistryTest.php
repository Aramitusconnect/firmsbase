<?php

namespace Tests\Feature\Webhooks\EventRegistry;

use App\Services\WebhookEventTypeRegistry;
use Tests\TestCase;

/**
 * Correction #15: exactly the 11 approved event types, no more, no
 * fewer. Unsupported types are rejected for both subscription creation
 * (covered in WebhookSubscriptionServiceTest) and event recording
 * (covered here + in WebhookEventRecorderServiceTest).
 */
class WebhookEventTypeRegistryTest extends TestCase
{
    public function test_exactly_the_11_approved_event_types_are_supported(): void
    {
        $expected = [
            'lead.created',
            'client.created',
            'matter.created',
            'document.uploaded',
            'invoice.created',
            'payment_plan.installment_due',
            'payment.recorded',
            'task.completed',
            'form.approved',
            'signature.completed',
            'matter.readiness_changed',
        ];

        $actual = WebhookEventTypeRegistry::supportedValues();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertCount(11, $actual);
    }

    public function test_unsupported_event_type_is_rejected(): void
    {
        $this->assertFalse(WebhookEventTypeRegistry::isSupported('not.a.real.event'));

        $this->expectException(\InvalidArgumentException::class);
        WebhookEventTypeRegistry::assertSupported('not.a.real.event');
    }

    public function test_assert_all_supported_rejects_if_any_one_value_is_unsupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebhookEventTypeRegistry::assertAllSupported(['matter.created', 'not.a.real.event']);
    }
}
