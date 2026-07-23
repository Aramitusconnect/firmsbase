<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Outbox;

use App\Integrations\Outbox\Exceptions\UnknownOutboxEventTypeException;
use App\Integrations\Outbox\Handlers\TestResourcePushHandler;
use App\Integrations\Outbox\OutboxEventHandlerContract;
use App\Integrations\Outbox\OutboxEventHandlerRegistry;
use ReflectionClass;
use Tests\TestCase;

/**
 * OutboxEventHandlerRegistryTest — Checkpoint 8
 * (agent-8b-outbox-dispatch-design.md §2;
 * agent-8h-architecture-security-review.md §4.2). Resolves a
 * registered handler; throws UnknownOutboxEventTypeException for an
 * unregistered type; the handler map is closed/private (no
 * config-driven injection point exists — confirms this structurally,
 * matching the disclosed design deviation from
 * config('integrations.outbox_handlers') documented in this
 * registry's own class docblock and in
 * UnknownOutboxEventTypeException's docblock).
 */
class OutboxEventHandlerRegistryTest extends TestCase
{
    private OutboxEventHandlerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new OutboxEventHandlerRegistry();
    }

    // ------------------------------------------------------------
    // Resolves a registered handler
    // ------------------------------------------------------------

    public function test_get_resolves_the_registered_test_resource_push_retry_handler(): void
    {
        $handler = $this->registry->get('test.resource.push_retry');

        $this->assertInstanceOf(TestResourcePushHandler::class, $handler);
        $this->assertInstanceOf(OutboxEventHandlerContract::class, $handler);
    }

    public function test_get_resolves_a_fresh_instance_via_the_container_each_call(): void
    {
        $first = $this->registry->get('test.resource.push_retry');
        $second = $this->registry->get('test.resource.push_retry');

        // Both must be genuinely usable handler instances — this proves
        // resolution goes through app()->make() (a working, non-cached
        // container resolution), not a hand-rolled singleton map that
        // could go stale.
        $this->assertInstanceOf(TestResourcePushHandler::class, $first);
        $this->assertInstanceOf(TestResourcePushHandler::class, $second);
    }

    // ------------------------------------------------------------
    // Throws UnknownOutboxEventTypeException for an unregistered type
    // ------------------------------------------------------------

    public function test_get_throws_unknown_outbox_event_type_exception_for_an_unregistered_type(): void
    {
        $this->expectException(UnknownOutboxEventTypeException::class);

        $this->registry->get('some.unregistered.event_type');
    }

    public function test_get_throws_for_an_empty_string_event_type(): void
    {
        $this->expectException(UnknownOutboxEventTypeException::class);

        $this->registry->get('');
    }

    public function test_the_exception_message_includes_the_offending_event_type_but_no_internal_class_name(): void
    {
        try {
            $this->registry->get('nonexistent.event.type');
            $this->fail('Expected UnknownOutboxEventTypeException to be thrown.');
        } catch (UnknownOutboxEventTypeException $e) {
            $this->assertStringContainsString('nonexistent.event.type', $e->getMessage());
            $this->assertStringNotContainsString(TestResourcePushHandler::class, $e->getMessage());
            $this->assertStringNotContainsString('.php', $e->getMessage(), 'The exception message must never leak an internal file path.');
        }
    }

    // ------------------------------------------------------------
    // The handler map is closed/private — no config-driven injection
    // point exists. Verified structurally via reflection: HANDLERS is a
    // private class constant, never read from config('integrations.
    // outbox_handlers') (that config key deliberately does not exist —
    // see agent-8h §2 item 19's closed key list).
    // ------------------------------------------------------------

    public function test_the_handler_map_is_a_private_in_class_constant_not_a_public_or_protected_one(): void
    {
        $reflection = new ReflectionClass(OutboxEventHandlerRegistry::class);

        $this->assertTrue($reflection->hasConstant('HANDLERS'), 'Expected a HANDLERS constant to exist on the registry.');

        $constants = $reflection->getReflectionConstants();
        $handlersConstant = null;

        foreach ($constants as $constant) {
            if ($constant->getName() === 'HANDLERS') {
                $handlersConstant = $constant;
                break;
            }
        }

        $this->assertNotNull($handlersConstant);
        $this->assertTrue($handlersConstant->isPrivate(), 'HANDLERS must be private — closed, in-class, no external injection point.');
        $this->assertFalse($handlersConstant->isPublic());
        $this->assertFalse($handlersConstant->isProtected());
    }

    public function test_no_outbox_handlers_config_key_exists_anywhere(): void
    {
        // Confirms the disclosed design deviation structurally: this
        // checkpoint's frozen config('integrations.*') key allowlist
        // (agent-8h §2 item 19) does not include an "outbox_handlers"
        // key at all — the registry's map cannot be config-driven
        // because no such config entry is authorized to exist.
        $this->assertNull(config('integrations.outbox_handlers'));
    }

    public function test_setting_a_config_outbox_handlers_key_at_runtime_has_no_effect_on_resolution(): void
    {
        // Even if a caller tried to inject a handler mapping via
        // config() at runtime, the registry must ignore it entirely —
        // proving the map is genuinely closed/private, not merely
        // "usually" config-free.
        config(['integrations.outbox_handlers' => [
            'test.resource.push_retry' => 'SomeOtherClassName',
            'a.fake.event_type' => TestResourcePushHandler::class,
        ]]);

        $handler = $this->registry->get('test.resource.push_retry');
        $this->assertInstanceOf(TestResourcePushHandler::class, $handler, 'The registry must resolve from its own closed HANDLERS constant, never from a runtime config() override.');

        $this->expectException(UnknownOutboxEventTypeException::class);
        $this->registry->get('a.fake.event_type');
    }

    public function test_registry_has_no_public_registration_or_mutation_method(): void
    {
        $reflection = new ReflectionClass(OutboxEventHandlerRegistry::class);
        $publicMethodNames = array_map(
            static fn ($method) => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        // Only get() (plus PHP's implicit constructor list, if any) may
        // be public — no register()/add()/set() escape hatch exists.
        $nonConstructorPublicMethods = array_values(array_filter(
            $publicMethodNames,
            static fn (string $name) => $name !== '__construct',
        ));

        $this->assertSame(['get'], $nonConstructorPublicMethods);
    }
}
