<?php

namespace Tests\Feature\Ai\Foundation;

use App\Enums\AiMode;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approved decision #1: AiMode replaced in place with exactly
 * disabled/platform_managed/firm_owned. Confirms the Phase 5
 * activation checklist's 'ai_mode' item (which only checks the column
 * is truthy/configured — any enum instance is truthy — remains
 * unaffected by this enum's case values changing) still passes.
 */
class AiModeEnumReplacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_mode_has_exactly_the_three_approved_cases(): void
    {
        $values = array_map(fn (AiMode $case) => $case->value, AiMode::cases());

        sort($values);

        $this->assertSame(['disabled', 'firm_owned', 'platform_managed'], $values);
    }

    public function test_firm_settings_ai_mode_defaults_to_disabled_via_factory(): void
    {
        $firm = Firm::factory()->create();
        $settings = $firm->firmSettings()->create([
            'payment_mode' => \App\Enums\PaymentMode::OperatingPaymentsOnly,
        ]);

        $this->assertSame(AiMode::Disabled, $settings->fresh()->ai_mode);
    }

    /**
     * FirmProductionActivationService::autoCompleteVerifiableItems()'s
     * 'ai_mode' check is exactly `(bool) $firm->firmSettings?->ai_mode`
     * (Phase 5) — any backed enum instance is truthy in PHP, so this
     * check is satisfied by every one of AiMode's three updated cases,
     * including Disabled. Confirmed directly here rather than via the
     * full checklist-seeding pipeline, which is out of Phase 15's scope
     * to set up.
     */
    public function test_activation_checklist_ai_mode_check_remains_satisfied_by_every_updated_case(): void
    {
        $firm = Firm::factory()->create();

        foreach (AiMode::cases() as $case) {
            $settings = $firm->firmSettings()->updateOrCreate([], [
                'payment_mode' => \App\Enums\PaymentMode::OperatingPaymentsOnly,
                'ai_mode' => $case,
            ]);

            $this->assertTrue((bool) $firm->fresh(['firmSettings'])->firmSettings?->ai_mode, "Case {$case->value} must remain truthy.");
        }
    }
}
