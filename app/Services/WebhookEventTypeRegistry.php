<?php

namespace App\Services;

use App\Enums\WebhookEventType;

/**
 * WebhookEventTypeRegistry — static, non-persisted single source of
 * truth for the 11 approved Phase 14 event types (correction #15). No
 * other event type may ever be subscribed to or recorded — every write
 * path (WebhookSubscriptionService::create()/updateEventTypes()'s
 * event_types array, WebhookEventRecorderService::record()'s type
 * argument) validates through this registry, so an unsupported type is
 * rejected by construction, not by convention.
 */
class WebhookEventTypeRegistry
{
    /**
     * @return list<string>
     */
    public static function supportedValues(): array
    {
        return array_map(fn (WebhookEventType $case) => $case->value, WebhookEventType::cases());
    }

    public static function isSupported(string $value): bool
    {
        return in_array($value, self::supportedValues(), true);
    }

    public static function assertSupported(string $value): WebhookEventType
    {
        $type = WebhookEventType::tryFrom($value);

        if ($type === null) {
            throw new \InvalidArgumentException("'{$value}' is not an approved Phase 14 webhook event type.");
        }

        return $type;
    }

    /**
     * @param list<string> $values
     */
    public static function assertAllSupported(array $values): void
    {
        foreach ($values as $value) {
            self::assertSupported($value);
        }
    }
}
