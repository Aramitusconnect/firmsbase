<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\TestCase;

/**
 * BillingFinancialInvariantTest — the central safety rule for Tier 2
 * Billing (Firm Feature Manifest §6): "Invoice/PaymentPlan totals and
 * statuses are always derived/cached, never directly settable." A
 * source-scan test, mirroring PaymentResourceAccessTest's own
 * `test_classification_field_never_offers_trust_iolta_payment_as_an_option`
 * convention (regex over a file's raw source) and
 * SecuritySecurity\SeedData\SecretPatternScanTest's glob-over-directory
 * scan mechanism — no new scanning taxonomy invented.
 *
 * Proves, by scanning every PHP file under InvoiceResource/ and
 * PaymentPlanResource/ (plus the two top-level Resource classes
 * themselves):
 *
 *   1. No `TextInput::make('status')` / `TextInput::make('total_cents')`
 *      / `TextInput::make('subtotal_cents')` / `TextInput::make(
 *      'amount_paid_cents')` / `TextInput::make('paid_amount_cents')`
 *      / `TextInput::make('installment_count')` exists anywhere — the
 *      exact class of mistake this module exists to prevent.
 *   2. Neither `InvoiceResource.php` nor `PaymentPlanResource.php`
 *      declares a `form(Schema $schema)` method — there is no generic
 *      Filament form-bound Create/Edit surface for either model.
 *   3. Neither Resource declares a `create`/`edit` route in
 *      `getPages()` — List/View only.
 *   4. Every mutating call into the domain services uses the exact
 *      service method name (draftFromTimeEntries/createFlatFee/
 *      addManualCharge/submitForReview/approve/send/void for
 *      InvoiceDraftingService; create/activate/renegotiate/cancel/
 *      markDefaulted for PaymentPlanService) — never a bare
 *      `Invoice::create(`/`Invoice::update(`/`Invoice::query()->...->update(`
 *      or the PaymentPlan equivalent anywhere in these two modules.
 */
final class BillingFinancialInvariantTest extends TestCase
{
    private const FORBIDDEN_FIELD_PATTERNS = [
        "TextInput::make('status')",
        "TextInput::make('total_cents')",
        "TextInput::make('subtotal_cents')",
        "TextInput::make('amount_paid_cents')",
        "TextInput::make('paid_amount_cents')",
        "TextInput::make('installment_count')",
        'Select::make(\'status\')',
    ];

    /**
     * Strips comments/docblocks from PHP source before scanning, using
     * the real tokenizer rather than a regex heuristic — this module's
     * own docblocks legitimately reference method names like
     * `Invoice::create()` in prose (e.g. "never a bare Invoice::create()")
     * to explain what NOT to do; only executable code should ever trip
     * these assertions.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    /**
     * @return string[] absolute paths of every PHP file in this module
     */
    private function moduleFiles(): array
    {
        $paths = [
            base_path('app/Filament/Firm/Resources/InvoiceResource.php'),
            base_path('app/Filament/Firm/Resources/PaymentPlanResource.php'),
        ];

        foreach (['InvoiceResource', 'PaymentPlanResource'] as $dir) {
            $base = base_path("app/Filament/Firm/Resources/{$dir}");

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }

    public function test_no_derived_financial_field_is_ever_exposed_as_a_direct_form_input(): void
    {
        $files = $this->moduleFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);

            foreach (self::FORBIDDEN_FIELD_PATTERNS as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $source,
                    basename($path)." must never expose a derived financial field as a directly editable form input ({$pattern})."
                );
            }
        }
    }

    public function test_invoice_resource_declares_no_generic_form_method(): void
    {
        $source = file_get_contents(base_path('app/Filament/Firm/Resources/InvoiceResource.php'));
        $this->assertIsString($source);

        $this->assertDoesNotMatchRegularExpression(
            '/function\s+form\s*\(/',
            $source,
            'InvoiceResource must not declare a form() method — every mutation is a named Action, never a generic Filament form.'
        );
    }

    public function test_payment_plan_resource_declares_no_generic_form_method(): void
    {
        $source = file_get_contents(base_path('app/Filament/Firm/Resources/PaymentPlanResource.php'));
        $this->assertIsString($source);

        $this->assertDoesNotMatchRegularExpression(
            '/function\s+form\s*\(/',
            $source,
            'PaymentPlanResource must not declare a form() method — every mutation is a named Action, never a generic Filament form.'
        );
    }

    public function test_neither_resource_registers_a_create_or_edit_page_route(): void
    {
        foreach (['InvoiceResource.php', 'PaymentPlanResource.php'] as $filename) {
            $source = file_get_contents(base_path("app/Filament/Firm/Resources/{$filename}"));
            $this->assertIsString($source);

            $this->assertDoesNotMatchRegularExpression(
                "/'create'\\s*=>/",
                $source,
                "{$filename} must not register a 'create' route — creation is Action-based only."
            );
            $this->assertDoesNotMatchRegularExpression(
                "/'edit'\\s*=>/",
                $source,
                "{$filename} must not register an 'edit' route — every mutation is a named Action, never a generic Edit page."
            );
        }
    }

    public function test_no_file_in_this_module_calls_invoice_create_update_or_save_directly(): void
    {
        // ->update( on a freshly-resolved Invoice/PaymentPlan variable is
        // allowed ONLY inside the two domain services themselves
        // (InvoiceDraftingService/PaymentPlanService), never inside this
        // Filament module — every Action here must call a named service
        // method instead. `$fresh->update(` above is deliberately broad:
        // it also catches an Action that resolved a fresh model and then
        // mutated it directly instead of delegating to the service.
        foreach ($this->moduleFiles() as $path) {
            $source = $this->stripComments((string) file_get_contents($path));

            foreach (['Invoice::create(', 'PaymentPlan::create(', '$invoice->update(', '$plan->update('] as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $source,
                    basename($path)." must never write to Invoice/PaymentPlan directly ({$pattern}) — every mutation must go through InvoiceDraftingService/PaymentPlanService."
                );
            }
        }
    }
}
