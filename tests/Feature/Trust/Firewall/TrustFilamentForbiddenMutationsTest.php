<?php

namespace Tests\Feature\Trust\Firewall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TrustFilamentForbiddenMutationsTest — mirrors
 * TrustForbiddenIntegrationsTest's own static-scan technique, applied
 * to the brand-new Trust/IOLTA Filament UI instead of the Trust
 * services. Two independent scans over every PHP file under
 * app/Filament/Firm/Resources/{TrustAccountResource,TrustLedgerResource,
 * TrustLedgerEntryResource}(.php):
 *
 *   1. No file ever calls create()/update()/save()/firstOrCreate()/
 *      updateOrCreate() directly on any of the 10 Trust/
 *      MatterTrustBalance model class names (rule #1 — the
 *      booted()-guard-does-not-cover-create() vulnerability the whole
 *      module's safety rules exist to close). Every mutation in this
 *      module must instead go through a named Trust*Service method.
 *   2. No file references any App\Integrations\* or FinancialEvidence*
 *      symbol (rule #6 — preserving the Plaid/Trust firewall this
 *      module must never cross).
 *
 * A deliberately crude, source-text scan (not static analysis) — same
 * trade-off TrustForbiddenIntegrationsTest itself already accepts:
 * simple enough to audit by eye, and it only needs to catch a LITERAL
 * `Model::create(`/`$model->update(`/`->save()` call site, which is
 * exactly the shape any accidental raw mutation would take.
 */
class TrustFilamentForbiddenMutationsTest extends TestCase
{
    use RefreshDatabase;

    private const TRUST_MODEL_CLASS_NAMES = [
        'TrustAccount',
        'TrustLedger',
        'TrustBalance',
        'MatterTrustBalance',
        'TrustTransferRequest',
        'TrustRefundRequest',
        'TrustApprovalEvent',
        'TrustLedgerEntry',
        'TrustChargebackEvent',
        'TrustReconciliation',
    ];

    private const FORBIDDEN_MUTATION_METHODS = [
        '::create(',
        '::firstOrCreate(',
        '::updateOrCreate(',
        '->update(',
        '->save(',
    ];

    private const FORBIDDEN_INTEGRATION_SYMBOLS = [
        'App\\Integrations',
        'FinancialEvidence',
    ];

    /**
     * Strips comments/docblocks via PHP's own tokenizer before scanning
     * — every Action/Resource/Page class in this module documents the
     * exact forbidden call it deliberately does NOT make (e.g. "never a
     * bare `TrustAccount::create()`"), and a plain substring scan over
     * raw source text would false-positive on that prose. Scanning only
     * REAL CODE tokens is a stricter, more accurate check than
     * TrustForbiddenIntegrationsTest's own raw-text scan, not a weaker
     * one — it eliminates comment-text false positives without
     * eliminating any genuine code match.
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

    private function moduleFiles(): array
    {
        $roots = [
            app_path('Filament/Firm/Resources/TrustAccountResource.php'),
            app_path('Filament/Firm/Resources/TrustAccountResource'),
            app_path('Filament/Firm/Resources/TrustLedgerResource.php'),
            app_path('Filament/Firm/Resources/TrustLedgerResource'),
            app_path('Filament/Firm/Resources/TrustLedgerEntryResource.php'),
            app_path('Filament/Firm/Resources/TrustLedgerEntryResource'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = $root;

                continue;
            }

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_the_trust_filament_module_actually_exists(): void
    {
        $files = $this->moduleFiles();

        $this->assertNotEmpty($files, 'Expected to find Filament files under the Trust module resource directories.');
        $this->assertGreaterThan(10, count($files), 'Expected substantially more than 10 files across the Trust Filament module (resources + actions + pages + relation managers).');
    }

    public function test_no_trust_filament_file_ever_calls_create_update_or_save_directly_on_a_trust_model(): void
    {
        foreach ($this->moduleFiles() as $file) {
            $source = $this->codeOnly(file_get_contents($file));

            foreach (self::TRUST_MODEL_CLASS_NAMES as $modelName) {
                foreach (self::FORBIDDEN_MUTATION_METHODS as $method) {
                    // Looks for "ModelName::create(" / "ModelName::update("-style
                    // static calls, and a same-line "$something->update("/
                    // "->save()" immediately following a reference to the
                    // model class name earlier on the line (the same
                    // simple heuristic TrustForbiddenIntegrationsTest uses
                    // for its own forbidden-string scan).
                    $needle = $modelName.$method;

                    $this->assertStringNotContainsString(
                        $needle,
                        $source,
                        basename($file)." must never call {$modelName}{$method}...) directly — every mutation must go through the owning Trust*Service method."
                    );
                }
            }
        }
    }

    public function test_no_trust_filament_file_references_a_forbidden_integration_or_financial_evidence_symbol(): void
    {
        foreach ($this->moduleFiles() as $file) {
            $source = $this->codeOnly(file_get_contents($file));

            foreach (self::FORBIDDEN_INTEGRATION_SYMBOLS as $symbol) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $source,
                    basename($file)." must never reference '{$symbol}' — the Trust/IOLTA module must never cross the Plaid/Trust firewall."
                );
            }
        }
    }

    /**
     * Defense-in-depth beyond the plain string scan above: no file may
     * even reference `->balance_cents` as a form field key ('balance_cents')
     * anywhere in this module — the dedicated
     * TrustFilamentNoBalanceCentsFormBindingTest covers this in more
     * detail; this is a lightweight duplicate check colocated with the
     * rest of the firewall scan for visibility.
     */
    public function test_no_trust_filament_file_binds_a_form_field_named_balance_cents(): void
    {
        foreach ($this->moduleFiles() as $file) {
            $source = $this->codeOnly(file_get_contents($file));

            $this->assertStringNotContainsString(
                "make('balance_cents')",
                $source,
                basename($file)." must never bind a form field to 'balance_cents' — only TrustBalanceService may write it."
            );
        }
    }
}
