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
 * `firm_user_2fa_mode`/`client_2fa_mode` have NO form field anywhere on
 * this page — total exclusion, per this task's explicit instruction —
 * and that `payment_mode`/`trust_iolta_protection`/`ai_mode` are never
 * bound to a writable form component either.
 */
class FirmSettingsPageForbiddenFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_2FA_COLUMNS = [
        'firm_user_2fa_mode',
        'client_2fa_mode',
    ];

    private const PLATFORM_MANAGED_COLUMNS = [
        'payment_mode',
        'trust_iolta_protection',
        'ai_mode',
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

    public function test_the_firm_settings_page_never_references_the_two_factor_mode_enum(): void
    {
        $source = $this->pageSource();

        $this->assertStringNotContainsString(
            'TwoFactorMode',
            $source,
            'FirmSettingsPage must never reference TwoFactorMode at all — it must not imply either 2FA column is configurable here.'
        );
    }

    public function test_the_firm_settings_page_mentions_2fa_at_most_once_as_plain_noninteractive_text(): void
    {
        $source = $this->pageSource();

        // The one permitted acknowledgement is a single plain Text::make
        // string, never a form field, Select, Toggle, or Action.
        $this->assertSame(
            1,
            substr_count($source, '2FA'),
            'FirmSettingsPage may mention "2FA" at most once — a single plain-text acknowledgement, nothing interactive.'
        );
        $this->assertStringContainsString(
            "Text::make('2FA policy: managed separately.')",
            $source,
            'The one permitted 2FA mention must be a plain, non-interactive Text component.'
        );
    }

    public function test_platform_managed_columns_are_never_bound_to_a_writable_form_component(): void
    {
        $source = $this->pageSource();

        foreach (self::PLATFORM_MANAGED_COLUMNS as $column) {
            foreach (['TextInput::make', 'Select::make', 'Toggle::make', 'Checkbox::make', 'Textarea::make'] as $writableComponent) {
                $this->assertStringNotContainsString(
                    "{$writableComponent}('{$column}'",
                    $source,
                    "FirmSettingsPage must never bind a WRITABLE form component ({$writableComponent}) to '{$column}' — it is platform-support-managed, display-only."
                );
            }
        }
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

        foreach ([...self::PLATFORM_MANAGED_COLUMNS, ...self::FORBIDDEN_2FA_COLUMNS] as $column) {
            $this->assertStringNotContainsString(
                "state['{$column}'",
                $saveBody,
                "save() must never read '{$column}' off form state."
            );
        }
    }
}
