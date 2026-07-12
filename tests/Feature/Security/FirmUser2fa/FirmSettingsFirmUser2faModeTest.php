<?php

namespace Tests\Feature\Security\FirmUser2fa;

use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FirmSettingsFirmUser2faModeTest — Section 39B. Proves the new
 * firm_user_2fa_mode column exists, defaults to a value that never
 * locks out an existing dev/test firm, and reuses the existing
 * TwoFactorMode enum (no new enum was created).
 */
class FirmSettingsFirmUser2faModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_firm_user_2fa_mode_column_exists_on_firm_settings(): void
    {
        $this->assertContains('firm_user_2fa_mode', Schema::getColumnListing('firm_settings'));
    }

    public function test_default_mode_does_not_lock_out_existing_dev_test_users(): void
    {
        // FirmSettingsFactory doesn't explicitly set firm_user_2fa_mode
        // (unlike client_2fa_mode), so this proves the raw DATABASE
        // column default itself is the safe 'optional' value — not
        // just an in-memory factory default — by re-reading the row.
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create();

        $this->assertSame(TwoFactorMode::Optional, FirmSettings::where('firm_id', $firm->id)->first()->firm_user_2fa_mode);
    }

    public function test_firm_user_2fa_mode_reuses_the_existing_two_factor_mode_enum(): void
    {
        $firm = Firm::factory()->create();
        $settings = FirmSettings::factory()->forFirm($firm)->create([
            'firm_user_2fa_mode' => TwoFactorMode::Required,
        ]);

        $this->assertInstanceOf(TwoFactorMode::class, $settings->firm_user_2fa_mode);
        $this->assertSame(TwoFactorMode::Required, $settings->fresh()->firm_user_2fa_mode);
    }

    public function test_firm_user_2fa_mode_is_fillable(): void
    {
        $firm = Firm::factory()->create();

        // Section 39A-3L, Checkpoint 18 — firm_settings gained permanent
        // FORCE ROW LEVEL SECURITY in this checkpoint. This test calls
        // the model's own static FirmSettings::create() directly (not
        // FirmSettings::factory()->create()), so Agent 9's factory-level
        // context-hold fix does not apply here — this INSERT genuinely
        // needs its own explicit tenant context now, the same way every
        // other direct/raw insert against a newly-forced table does.
        $freshMode = $this->runWithFirmContext($firm, function () use ($firm) {
            $settings = FirmSettings::create([
                'firm_id' => $firm->id,
                'firm_user_2fa_mode' => TwoFactorMode::Required,
            ]);

            return $settings->fresh()->firm_user_2fa_mode;
        });

        $this->assertSame(TwoFactorMode::Required, $freshMode);
    }
}
