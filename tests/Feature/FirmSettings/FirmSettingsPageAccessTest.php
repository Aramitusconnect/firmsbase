<?php

declare(strict_types=1);

namespace Tests\Feature\FirmSettings;

use App\Enums\AiMode;
use App\Enums\FirmUserRole;
use App\Enums\PaymentMode;
use App\Enums\TwoFactorMode;
use App\Filament\Firm\Pages\FirmSettingsPage;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * FirmSettingsPageAccessTest — Firm Feature Manifest §13 UI proof
 * (Tier 3-C): (1) every active role may VIEW the page, a guest may not;
 * (2) the page correctly splits and displays current values across
 * `Firm` and `FirmSettings`; (3) Save is visible/effective for
 * FirmOwner only, and blocked (defense-in-depth) even if a non-owner
 * forces the `save` call directly; (4) a forged value for a
 * platform-managed column (payment_mode/trust_iolta_protection/
 * ai_mode) submitted alongside a legitimate save has no effect; (5) the
 * small RLS regression checklist — a firm's own settings save only ever
 * reads/writes its own firm's `Firm`/`FirmSettings` rows, never a
 * foreign firm's. Matches `FirmUserResourceAccessTest`'s own established
 * style.
 */
final class FirmSettingsPageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() role ceiling — every active role may VIEW.
    // ------------------------------------------------------------

    public function test_every_active_role_can_access_the_firm_settings_page(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
            $this->actingAsRole($firm, $role);

            $this->assertTrue(FirmSettingsPage::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_guest_cannot_access_the_firm_settings_page(): void
    {
        $this->assertFalse(FirmSettingsPage::canAccess());
    }

    public function test_guest_is_redirected_away_from_the_firm_settings_page_route(): void
    {
        $response = $this->get(FirmSettingsPage::getUrl());

        $response->assertRedirect();
    }

    // ------------------------------------------------------------
    // 2. Renders and splits current values across Firm / FirmSettings.
    // ------------------------------------------------------------

    public function test_the_page_renders_and_shows_current_values_split_across_firm_and_firm_settings(): void
    {
        $firm = Firm::factory()->create([
            'legal_name' => 'Acme Legal LLC',
            'primary_country' => 'US',
            'primary_state' => 'NY',
            'default_timezone' => 'America/New_York',
            'default_currency' => 'USD',
        ]);

        $settings = $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create([
            'default_language' => 'en',
            'state_jurisdiction' => 'NY',
            'payment_mode' => PaymentMode::OperatingAndTrust,
            'trust_iolta_protection' => true,
            'ai_mode' => AiMode::PlatformManaged,
            'client_2fa_mode' => TwoFactorMode::Required,
            'portal_frontend_mode' => 'legacy',
        ]));

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSettingsPage::class));

        $test->assertSet('data.legal_name', 'Acme Legal LLC');
        $test->assertSet('data.primary_country', 'US');
        $test->assertSet('data.primary_state', 'NY');
        $test->assertSet('data.default_timezone', 'America/New_York');
        $test->assertSet('data.default_currency', 'USD');
        $test->assertSet('data.default_language', 'en');
        $test->assertSet('data.state_jurisdiction', 'NY');

        // Platform-managed values are captured as plain scalar display
        // properties, never as writable 'data.*' form state.
        $test->assertSet('paymentModeDisplay', PaymentMode::OperatingAndTrust->value);
        $test->assertSet('trustIoltaProtectionDisplay', true);
        $test->assertSet('aiModeDisplay', AiMode::PlatformManaged->value);
        // SET-001: client_2fa_mode is now a live, mount()-captured
        // display value (previously a static, unbound "managed
        // separately" label).
        $test->assertSet('client2faModeDisplay', TwoFactorMode::Required->value);
        // SET-003: portal_frontend_mode is a plain nullable string
        // column (no enum cast on FirmSettings) — displayed as its raw
        // value for visibility/parity only, same as client_2fa_mode.
        $test->assertSet('portalFrontendModeDisplay', 'legacy');

        $test->assertSee('Payment Mode: operating_and_trust');
        $test->assertSee('Trust/IOLTA Protection: Enabled');
        $test->assertSee('AI Mode: platform_managed');
        $test->assertSee('Client 2FA Policy: required');
        $test->assertSee('Portal Frontend Mode: legacy');

        unset($settings);
    }

    public function test_new_address_and_phone_fields_render_as_null_when_unset(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSettingsPage::class));

        foreach (['address_line1', 'address_line2', 'city', 'postal_code', 'phone_number'] as $field) {
            $test->assertSet("data.{$field}", null);
        }
    }

    /**
     * SET-001/SET-003: when client_2fa_mode is at its factory default
     * (TwoFactorMode::Optional, never null) and portal_frontend_mode is
     * genuinely unset (factory default null), the page still renders a
     * live display for the former and an em-dash fallback for the
     * latter — never the old static "managed separately" placeholder.
     */
    public function test_client_2fa_mode_and_portal_frontend_mode_render_with_their_live_values(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSettingsPage::class));

        $test->assertSet('client2faModeDisplay', TwoFactorMode::Optional->value);
        $test->assertSet('portalFrontendModeDisplay', null);

        $test->assertSee('Client 2FA Policy: optional');
        $test->assertSee('Portal Frontend Mode: —');
        $test->assertDontSee('2FA policy: managed separately.');
    }

    // ------------------------------------------------------------
    // 3. Save — FirmOwner only, both UI-visible and defense-in-depth.
    // ------------------------------------------------------------

    public function test_save_action_is_visible_for_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSettingsPage::class));

        // The save Action is embedded directly in the page's content
        // Schema (matching PlaidUsagePolicyPage's own shape) rather than
        // registered through Filament's InteractsWithActions/HasActions
        // mounted-action lifecycle, so assertActionVisible()/
        // assertActionHidden() (which require that lifecycle) do not
        // apply here — visibility is asserted at the rendered-HTML level
        // instead, which is exactly what the Action's own ->visible()
        // closure controls.
        $test->assertSee('Save Settings');
    }

    public function test_save_action_is_hidden_for_every_non_owner_role(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            if ($role === FirmUserRole::FirmOwner) {
                continue;
            }

            $firm = Firm::factory()->create();
            $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSettingsPage::class));
            $test->assertDontSee('Save Settings');
        }
    }

    public function test_firm_owner_can_save_a_legitimate_change_across_both_models(): void
    {
        $firm = Firm::factory()->create(['legal_name' => 'Old Name']);
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create(['default_language' => 'en']));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $test = Livewire::test(FirmSettingsPage::class);
            $test->set('data.legal_name', 'New Name LLC');
            $test->set('data.address_line1', '123 Main St');
            $test->set('data.city', 'Springfield');
            $test->set('data.default_language', 'fr');
            $test->set('data.state_jurisdiction', 'CA');
            $test->set('data.branding_primary_color', '#1D4ED8');
            $test->call('save');
            $test->assertHasNoErrors();

            $freshFirm = Firm::query()->find($firm->id);
            $this->assertSame('New Name LLC', $freshFirm->legal_name);
            $this->assertSame('123 Main St', $freshFirm->address_line1);
            $this->assertSame('Springfield', $freshFirm->city);

            $freshSettings = FirmSettings::query()->where('firm_id', $firm->id)->first();
            $this->assertSame('fr', $freshSettings->default_language);
            $this->assertSame('CA', $freshSettings->state_jurisdiction);
            $this->assertSame('#1D4ED8', $freshSettings->branding_settings_json['primary_color'] ?? null);
        });
    }

    public function test_non_owner_forcing_the_save_call_directly_is_blocked_with_a_403(): void
    {
        $firm = Firm::factory()->create(['legal_name' => 'Untouched']);
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $test = Livewire::test(FirmSettingsPage::class);
            $test->set('data.legal_name', 'Smuggled Name');

            // save()'s own abort_unless(403) throws an HttpException,
            // but Livewire's test call() lifecycle catches it and
            // renders it into the underlying HTTP response rather than
            // letting it bubble up as a PHP exception here — so the
            // 403 is asserted the same way an HTTP test would, via the
            // response Livewire's Testable proxies unknown assertions
            // to (see Livewire\Features\SupportTesting\Testable::__call()).
            $test->call('save')->assertForbidden();

            $fresh = Firm::query()->find($firm->id);
            $this->assertSame('Untouched', $fresh->legal_name, 'A blocked save must never mutate the firm row.');
        });
    }

    // ------------------------------------------------------------
    // 4. Platform-managed fields — forged submission has no effect.
    // ------------------------------------------------------------

    public function test_forging_a_platform_managed_value_via_data_has_no_effect_on_save(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create([
            'payment_mode' => PaymentMode::OperatingPaymentsOnly,
            'trust_iolta_protection' => true,
            'ai_mode' => AiMode::Disabled,
        ]));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $test = Livewire::test(FirmSettingsPage::class);

            // Attempt to smuggle values for the three platform-managed
            // columns directly into the Livewire component's data
            // property — none of these keys is ever read by save().
            $test->set('data.payment_mode', PaymentMode::Blocked->value);
            $test->set('data.trust_iolta_protection', false);
            $test->set('data.ai_mode', AiMode::FirmOwned->value);
            $test->set('data.legal_name', 'Legit Change');

            $test->call('save');
            $test->assertHasNoErrors();

            $fresh = FirmSettings::query()->where('firm_id', $firm->id)->first();
            $this->assertSame(PaymentMode::OperatingPaymentsOnly, $fresh->payment_mode, 'payment_mode must never be settable through this page.');
            $this->assertTrue($fresh->trust_iolta_protection, 'trust_iolta_protection must never be settable through this page.');
            $this->assertSame(AiMode::Disabled, $fresh->ai_mode, 'ai_mode must never be settable through this page.');

            $freshFirm = Firm::query()->find($firm->id);
            $this->assertSame('Legit Change', $freshFirm->legal_name, 'The legitimate field change must still have saved.');
        });
    }

    public function test_forging_a_client_2fa_mode_value_via_data_has_no_effect_on_save(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
        $originalClient2fa = $this->runWithFirmContext($firm, fn () => FirmSettings::query()->where('firm_id', $firm->id)->first()->client_2fa_mode);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm, $originalClient2fa): void {
            $test = Livewire::test(FirmSettingsPage::class);
            $test->set('data.client_2fa_mode', 'required');
            $test->call('save');
            $test->assertHasNoErrors();

            $fresh = FirmSettings::query()->where('firm_id', $firm->id)->first();
            $this->assertSame($originalClient2fa, $fresh->client_2fa_mode, 'client_2fa_mode must never be settable through this page.');
        });
    }

    /**
     * SET-002 (Non-Payment Completion Program, Workstream 11):
     * firm_user_2fa_mode is now a real, FirmOwner-only editable field —
     * safe now that the platform-minimum MFA floor removes the lockout
     * risk that previously justified excluding it entirely.
     */
    public function test_firm_owner_can_set_firm_user_2fa_mode_to_required(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Optional]));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $test = Livewire::test(FirmSettingsPage::class);
            $test->set('data.firm_user_2fa_mode', TwoFactorMode::Required->value);
            $test->call('save');
            $test->assertHasNoErrors();

            $fresh = FirmSettings::query()->where('firm_id', $firm->id)->first();
            $this->assertSame(TwoFactorMode::Required, $fresh->firm_user_2fa_mode, 'firm_user_2fa_mode must be settable by a FirmOwner through this page.');
        });
    }

    public function test_a_non_owner_role_cannot_change_firm_user_2fa_mode_even_via_a_forced_save_call(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Optional]));
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $test = Livewire::test(FirmSettingsPage::class);
            $test->set('data.firm_user_2fa_mode', TwoFactorMode::Required->value);

            try {
                $test->call('save');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }

            $fresh = FirmSettings::query()->where('firm_id', $firm->id)->first();
            $this->assertSame(TwoFactorMode::Optional, $fresh->firm_user_2fa_mode, 'A non-owner must never be able to change firm_user_2fa_mode, even by forcing the save action.');
        });
    }

    // ------------------------------------------------------------
    // 5. RLS regression checklist.
    // ------------------------------------------------------------

    public function test_a_firms_settings_page_only_loads_its_own_firm_and_firm_settings_rows(): void
    {
        $firmA = Firm::factory()->create(['legal_name' => 'Firm A']);
        $firmB = Firm::factory()->create(['legal_name' => 'Firm B']);
        $this->runWithFirmContext($firmA, fn () => FirmSettings::factory()->forFirm($firmA)->create(['default_language' => 'en']));
        $this->runWithFirmContext($firmB, fn () => FirmSettings::factory()->forFirm($firmB)->create(['default_language' => 'de']));

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(FirmSettingsPage::class));

        $test->assertSet('data.legal_name', 'Firm A');
        $test->assertSet('data.default_language', 'en');
    }

    public function test_saving_firm_a_settings_never_touches_firm_bs_rows(): void
    {
        $firmA = Firm::factory()->create(['legal_name' => 'Firm A']);
        $firmB = Firm::factory()->create(['legal_name' => 'Firm B']);
        $this->runWithFirmContext($firmA, fn () => FirmSettings::factory()->forFirm($firmA)->create(['default_language' => 'en']));
        $this->runWithFirmContext($firmB, fn () => FirmSettings::factory()->forFirm($firmB)->create(['default_language' => 'de']));

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firmA, function (): void {
            $test = Livewire::test(FirmSettingsPage::class);
            $test->set('data.legal_name', 'Firm A Updated');
            $test->set('data.default_language', 'es');
            $test->call('save');
            $test->assertHasNoErrors();
        });

        $freshA = Firm::query()->find($firmA->id);
        $this->assertSame('Firm A Updated', $freshA->legal_name);

        $freshB = Firm::query()->find($firmB->id);
        $this->assertSame('Firm B', $freshB->legal_name, "Firm B's own row must be completely unaffected by firm A's save.");

        $freshSettingsB = $this->runWithFirmContext($firmB, fn () => FirmSettings::query()->where('firm_id', $firmB->id)->first());
        $this->assertSame('de', $freshSettingsB->default_language, "Firm B's own FirmSettings row must be completely unaffected by firm A's save.");
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
