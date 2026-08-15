<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\AiPolicySetting;
use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
use App\Services\AiPolicySettingService;
use App\Services\Configuration\AiPolicyDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The platform-wide AI kill switch lives in a generic (key, value_json)
 * table that also had a generic raw-JSON row editor pointed at it. That
 * combination produced two real failure modes, both closed here and
 * both asserted against the SERVICE rather than the form:
 *
 *   1. A SECOND, UNGOVERNED PATH to the kill switch — the raw editor
 *      could write 'platform_ai_enabled' directly, bypassing the
 *      step-up re-authentication and mandatory reason that
 *      ToggleAiKillSwitchAction enforces.
 *
 *   2. SILENT DISENGAGEMENT — platformKillSwitchEngaged() compares
 *      strictly (`=== false`), so storing the string "false", or 0, or
 *      null, leaves AI enabled platform-wide while looking to the
 *      operator like the switch was set.
 *
 * Mission section 55 forbids building a SECOND kill switch. This does
 * the opposite: it removes a second, unsafe way to operate the one that
 * already exists.
 */
class AiPolicyGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private AiPolicySettingService $settings;

    private AiPolicyDefinitionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(AiPolicySettingService::class);
        $this->registry = app(AiPolicyDefinitionRegistry::class);
    }

    public function test_the_kill_switch_key_is_recognized_typed_and_governed(): void
    {
        $key = AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY;

        $this->assertTrue($this->registry->isRecognized($key));
        $this->assertTrue($this->registry->isGoverned($key));
        $this->assertSame(AiPolicyDefinitionRegistry::TYPE_BOOLEAN, $this->registry->find($key)['type']);
    }

    /**
     * The registry must be derived from real consumers, not invented.
     */
    public function test_no_policy_key_is_defined_without_a_named_consumer(): void
    {
        foreach ($this->registry->definitions() as $key => $definition) {
            $this->assertArrayHasKey('consumer', $definition, "{$key} must name the service that reads it");
            $this->assertNotEmpty($definition['consumer']);
        }
    }

    public function test_an_unrecognized_key_is_reported_as_unconsumed_rather_than_given_a_type(): void
    {
        $this->assertFalse($this->registry->isRecognized('some_undefined_key'));
        $this->assertNull($this->registry->find('some_undefined_key'));
        $this->assertStringContainsString(
            'Not consumed by any service',
            $this->registry->describeValue('some_undefined_key', 'anything'),
        );
    }

    public function test_the_generic_editor_cannot_write_the_governed_kill_switch_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/governed and cannot be edited here/i');

        $this->settings->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);
    }

    public function test_the_governed_key_refusal_names_the_control_that_should_be_used(): void
    {
        try {
            $this->settings->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);
            $this->fail('expected the governed write to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('AI Oversight', $e->getMessage());
        }
    }

    public function test_the_canonical_governed_path_may_still_write_the_kill_switch(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);

        $setting = $this->settings->set(
            AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY,
            false,
            $actor,
            'Incident response',
            allowGovernedKey: true,
        );

        $this->assertFalse($setting->value_json);
    }

    /**
     * The silent-disengagement hole.
     */
    public function test_a_non_boolean_value_for_the_kill_switch_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a boolean/i');

        $this->settings->set(
            AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY,
            'false',
            allowGovernedKey: true,
        );
    }

    public function test_a_kill_switch_value_of_null_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, null, allowGovernedKey: true);
    }

    /**
     * Proves the refusal above actually protects real behaviour: had the
     * string been stored, the kill switch would have read as disengaged.
     */
    public function test_the_engaged_kill_switch_cannot_be_silently_disengaged_by_a_string_value(): void
    {
        $this->settings->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false, allowGovernedKey: true);
        $this->assertTrue(app(AiModeResolutionService::class)->platformKillSwitchEngaged());

        try {
            $this->settings->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, 'false', allowGovernedKey: true);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertTrue(
            app(AiModeResolutionService::class)->platformKillSwitchEngaged(),
            'the kill switch must remain engaged — a rejected write must not disengage it',
        );
    }

    public function test_an_unrecognized_key_is_still_freely_editable_as_generic_json(): void
    {
        // This table is deliberately generic. Mission section 52 forbids
        // inventing types for keys with no consumer, so undefined keys
        // must NOT be constrained.
        $setting = $this->settings->set('some_future_key', ['nested' => ['value' => 1]]);

        $this->assertSame(['nested' => ['value' => 1]], $setting->value_json);
    }

    public function test_writing_an_unrecognized_key_is_still_audited(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->settings->set('some_future_key', true, $actor);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'ai_policy_setting_created',
            'actor_id' => $actor->id,
        ]);
    }

    public function test_the_kill_switch_value_is_described_in_operator_language(): void
    {
        $key = AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY;

        $this->assertStringContainsString('ENGAGED', $this->registry->describeValue($key, false));
        $this->assertStringContainsString('Not engaged', $this->registry->describeValue($key, true));
    }

    public function test_no_definition_exposes_a_secret(): void
    {
        foreach ($this->registry->definitions() as $key => $definition) {
            $this->assertFalse($definition['secret'], "{$key} must not be a secret-bearing key rendered in the console");
        }
    }

    /**
     * Mission section 55 — this mission must not introduce a second
     * emergency shutdown mechanism.
     */
    public function test_no_second_kill_switch_key_is_introduced(): void
    {
        $availabilityKeys = collect($this->registry->definitions())
            ->filter(fn (array $d): bool => $d['category'] === AiPolicyDefinitionRegistry::CATEGORY_AVAILABILITY)
            ->keys();

        $this->assertSame(
            [AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY],
            $availabilityKeys->all(),
            'exactly one platform AI availability switch may exist',
        );
    }

    public function test_absent_row_semantics_are_documented_rather_than_assumed(): void
    {
        AiPolicySetting::query()->delete();

        $this->assertFalse(
            app(AiModeResolutionService::class)->platformKillSwitchEngaged(),
            'an absent row must continue to mean "not engaged" — unchanged pre-existing behaviour',
        );

        $this->assertStringContainsString(
            'Enabled',
            $this->registry->find(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY)['absent_meaning'],
        );
    }
}
