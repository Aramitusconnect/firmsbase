<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiMode;
use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Services\AiModeResolutionService;
use App\Services\AiPolicySettingService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 4 — the
 * platform-wide AI kill switch, independent of any single firm's own
 * mode/entitlement/keys. AiModeResolutionService is the single gate
 * every AI consumer in the codebase already calls, so proving it here
 * proves it for the whole platform, not just MyAttorney.
 */
class AiModeResolutionServicePlatformKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function firmWithAiEnabled(): Firm
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmSettings::factory()->create(['firm_id' => $firm->id, 'ai_mode' => AiMode::PlatformManaged]);
        });
        app(EntitlementService::class)->setForSource($firm, 'ai', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    public function test_kill_switch_is_not_engaged_when_no_policy_row_exists(): void
    {
        $this->assertFalse(app(AiModeResolutionService::class)->platformKillSwitchEngaged());
    }

    public function test_kill_switch_is_not_engaged_when_explicitly_set_true(): void
    {
        app(AiPolicySettingService::class)->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, true);

        $this->assertFalse(app(AiModeResolutionService::class)->platformKillSwitchEngaged());
    }

    public function test_kill_switch_is_engaged_when_explicitly_set_false(): void
    {
        app(AiPolicySettingService::class)->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);

        $this->assertTrue(app(AiModeResolutionService::class)->platformKillSwitchEngaged());
    }

    public function test_a_normally_ai_enabled_firm_still_passes_evaluate_when_kill_switch_is_absent(): void
    {
        $firm = $this->firmWithAiEnabled();

        $decision = $this->runWithFirmContext($firm, fn () => app(AiModeResolutionService::class)->evaluate($firm));

        $this->assertTrue($decision->allowed);
    }

    public function test_a_normally_ai_enabled_firm_is_blocked_once_the_platform_kill_switch_engages(): void
    {
        $firm = $this->firmWithAiEnabled();
        app(AiPolicySettingService::class)->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);

        $decision = $this->runWithFirmContext($firm, fn () => app(AiModeResolutionService::class)->evaluate($firm));

        $this->assertFalse($decision->allowed);
        $this->assertSame('AI is currently disabled platform-wide.', $decision->reason);
    }

    public function test_the_kill_switch_overrides_every_firm_regardless_of_their_own_settings(): void
    {
        $firmA = $this->firmWithAiEnabled();
        $firmB = $this->firmWithAiEnabled();
        app(AiPolicySettingService::class)->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);

        $this->assertFalse($this->runWithFirmContext($firmA, fn () => app(AiModeResolutionService::class)->evaluate($firmA))->allowed);
        $this->assertFalse($this->runWithFirmContext($firmB, fn () => app(AiModeResolutionService::class)->evaluate($firmB))->allowed);
    }

    public function test_re_enabling_the_platform_switch_restores_normal_behavior(): void
    {
        $firm = $this->firmWithAiEnabled();
        $service = app(AiPolicySettingService::class);
        $service->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);
        $service->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, true);

        $decision = $this->runWithFirmContext($firm, fn () => app(AiModeResolutionService::class)->evaluate($firm));

        $this->assertTrue($decision->allowed);
    }
}
