<?php

declare(strict_types=1);

namespace Tests\Feature\FirmSettings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmSettingsPageForbiddenFieldsTest — mirrors
 * TrustFilamentForbiddenMutationsTest's/ClientResourceAccessTest's own
 * static-scan technique (Firm Feature Manifest §13, Tier 3-C scope
 * boundary): a source-text scan over FirmSettingsPage.php proving
 * `client_2fa_mode` has NO form field anywhere on this page — total
 * exclusion, since Client Portal has no equivalent enrollment-safety
 * work yet — and that `payment_mode`/`trust_iolta_protection`/`ai_mode`
 * are never bound to a writable form component either. `client_2fa_mode`
 * (SET-001) and `portal_frontend_mode` (SET-003) each got a live,
 * read-only Text display in a later pass — still no form field, still
 * never read by `save()`, which this file also still proves.
 *
 * `firm_user_2fa_mode` was deliberately excluded through SET-001, but
 * SET-002 (Non-Payment Completion Program, Workstream 11) made it a
 * real, FirmOwner-only editable field now that the platform-minimum MFA
 * floor removes the lockout risk that justified the original exclusion
 * — see FirmSettingsPageAccessTest for the positive coverage proving it
 * now saves correctly and is gated by the same MANAGE ceiling as every
 * other field on this page.
 */
class FirmSettingsPageForbiddenFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_2FA_COLUMNS = [
        'client_2fa_mode',
    ];

    private const PLATFORM_MANAGED_COLUMNS = [
        'payment_mode',
        'trust_iolta_protection',
        'ai_mode',
    ];

    /**
     * SET-003: portal_frontend_mode is a plain nullable string column
     * with no live consumer anywhere in the codebase (confirmed dead/
     * write-only scaffolding — see FirmSettingsPage's own updated
     * docblock). Same treatment as the platform-managed columns above:
     * a read-only Text display only, never a writable form component,
     * never read by save().
     */
    private const DISPLAY_ONLY_COLUMNS = [
        'portal_frontend_mode',
    ];

    /**
     * Strips comments/docblocks via PHP's own tokenizer before scanning
     * — this class's own docblock legitimately discusses every one of
     * these column names in prose, and a raw substring scan over the
     * file would false-positive on that prose. Matches
     * TrustFilamentForbiddenMutationsTest::codeOnly()'s own approach.
     */
    private function codeOnly(string $source): string
    {
        $tokens = token_get_all($source);
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    private function pageSource(): string
    {
        $path = app_path('Filament/Firm/Pages/FirmSettingsPage.php');

        $this->assertFileExists($path, 'Expected FirmSettingsPage.php to exist.');

        return $this->codeOnly(file_get_contents($path));
    }

    public function test_the_firm_settings_page_never_declares_a_form_field_for_either_2fa_column(): void
    {
        $source = $this->pageSource();

        foreach (self::FORBIDDEN_2FA_COLUMNS as $column) {
            $this->assertStringNotContainsString(
                "make('{$column}'",
                $source,
                "FirmSettingsPage must never bind a form component to '{$column}' — 2FA policy is handled by a separate, not-yet-built task."
            );
            $this->assertStringNotContainsString(
                "make(\"{$column}\"",
                $source,
                "FirmSettingsPage must never bind a form component to \"{$column}\" — 2FA policy is handled by a separate, not-yet-built task."
            );
        }
    }

    public function test_the_firm_settings_page_references_two_factor_mode_only_for_the_firm_user_select(): void
    {
        $source = $this->pageSource();

        // SET-002: TwoFactorMode is now legitimately referenced, but
        // only to power firm_user_2fa_mode's Select options — never in
        // a way that implies client_2fa_mode is configurable here.
        $this->assertStringContainsString(
            'use App\Enums\TwoFactorMode;',
            $source,
            'FirmSettingsPage must import TwoFactorMode to power the firm_user_2fa_mode Select.'
        );
        $this->assertStringContainsString(
            "Select::make('firm_user_2fa_mode')",
            $source,
            'firm_user_2fa_mode must be a real Select field now (SET-002).'
        );
        $this->assertStringNotContainsString(
            "Select::make('client_2fa_mode')",
            $source,
            'client_2fa_mode must never become a form field — no equivalent enrollment-safety work exists for Client Portal yet.'
        );
    }

    public function test_the_firm_settings_page_shows_a_readonly_text_display_for_client_2fa_mode(): void
    {
        $source = $this->pageSource();

        // SET-001: client_2fa_mode's one permitted "2FA" display mention
        // is a live, read-only Text bound to client2faModeDisplay (a
        // plain scalar snapshot captured in mount(), mirroring
        // paymentModeDisplay/aiModeDisplay) — never a form field,
        // Select, Toggle, or Action.
        $this->assertStringContainsString(
            "Text::make(fn (): string => 'Client 2FA Policy: '.(\$this->client2faModeDisplay ?? '—'))",
            $source,
            'client_2fa_mode must be shown as a read-only Text component bound to its live value.'
        );
        $this->assertStringNotContainsString(
            "Text::make('2FA policy: managed separately.')",
            $source,
            'The old static, unbound 2FA placeholder text must be gone now that client_2fa_mode has a live display.'
        );
    }

    public function test_platform_managed_columns_are_never_bound_to_a_writable_form_component(): void
    {
        $source = $this->pageSource();

        foreach ([...self::PLATFORM_MANAGED_COLUMNS, ...self::DISPLAY_ONLY_COLUMNS] as $column) {
            foreach (['TextInput::make', 'Select::make', 'Toggle::make', 'Checkbox::make', 'Textarea::make'] as $writableComponent) {
                $this->assertStringNotContainsString(
                    "{$writableComponent}('{$column}'",
                    $source,
                    "FirmSettingsPage must never bind a WRITABLE form component ({$writableComponent}) to '{$column}' — it is platform-support-managed, display-only."
                );
            }
        }
    }

    public function test_the_firm_settings_page_shows_a_readonly_text_display_for_portal_frontend_mode(): void
    {
        $source = $this->pageSource();

        $this->assertStringContainsString(
            "Text::make(fn (): string => 'Portal Frontend Mode: '",
            $source,
            'FirmSettingsPage must show a read-only Text display for portal_frontend_mode (SET-003 visibility fix).'
        );
    }

    public function test_save_never_reads_a_platform_managed_or_2fa_key_off_form_state(): void
    {
        $source = $this->pageSource();

        // Isolate the save() method body so this check is scoped to the
        // actual persistence path, not the read-only display section
        // (which legitimately reads these values through
        // paymentModeDisplay/trustIoltaProtectionDisplay/aiModeDisplay,
        // populated in mount(), never in save()).
        $this->assertMatchesRegularExpression('/function save\(\):\s*void\s*\{.*?\n    \}/s', $source);
        preg_match('/function save\(\):\s*void\s*\{(.*?)\n    \}/s', $source, $matches);
        $saveBody = $matches[1] ?? '';

        $this->assertNotSame('', $saveBody, 'Could not isolate save() method body for scanning.');

        foreach ([...self::PLATFORM_MANAGED_COLUMNS, ...self::FORBIDDEN_2FA_COLUMNS, ...self::DISPLAY_ONLY_COLUMNS] as $column) {
            $this->assertStringNotContainsString(
                "state['{$column}'",
                $saveBody,
                "save() must never read '{$column}' off form state."
            );
        }
    }
}
