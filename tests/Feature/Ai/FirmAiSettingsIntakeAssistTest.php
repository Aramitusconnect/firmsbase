<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Firm;
use App\Models\FirmAiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 4 —
 * firm_ai_settings.intake_ai_assist_enabled: a reserved, currently-
 * unread toggle for a later checkpoint's AI-assisted intake features.
 * Defaults to false (opt-in), matching every other AI-behavior toggle
 * on this table.
 */
class FirmAiSettingsIntakeAssistTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_ai_assist_enabled_defaults_to_false(): void
    {
        $firm = Firm::factory()->create();
        $settings = $this->runWithFirmContext($firm, fn () => FirmAiSettings::factory()->forFirm($firm)->create());

        $this->assertFalse($settings->intake_ai_assist_enabled);
    }

    public function test_intake_ai_assist_enabled_can_be_toggled_on(): void
    {
        $firm = Firm::factory()->create();
        $settings = $this->runWithFirmContext($firm, fn () => FirmAiSettings::factory()->forFirm($firm)->create(['intake_ai_assist_enabled' => true]));

        $this->assertTrue($settings->intake_ai_assist_enabled);
    }
}
