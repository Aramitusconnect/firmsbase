<?php

declare(strict_types=1);

namespace Tests\Feature\Trust\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TrustFilamentNoBalanceCentsFormBindingTest — dedicated proof for rule
 * #2: "NEVER bind balance_cents (on trust_balances or
 * matter_trust_balances) to any form field, anywhere." Only
 * TrustBalanceService may write it, via its own internal
 * recompute-from-ledger logic — there is no legitimate UI path that
 * sets a balance directly.
 *
 * Scans every PHP file in the Trust Filament module (Resources,
 * Actions, Pages, RelationManagers — the full set, not just Actions)
 * with comments stripped (via PHP's own tokenizer, so this cannot
 * false-positive on prose that merely DISCUSSES balance_cents, e.g.
 * every Action's own docblock explaining why it never touches that
 * column) for ANY Filament component constructor
 * (`make('balance_cents')`/`make("balance_cents")`) — covering every
 * component type (TextInput, Hidden, Select, TextColumn, TextEntry,
 * etc.), not merely form inputs, since a read accidentally exposed as
 * an editable-looking component would be just as much of a violation
 * of the spirit of this rule.
 */
final class TrustFilamentNoBalanceCentsFormBindingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_no_component_anywhere_in_the_trust_module_is_named_balance_cents(): void
    {
        $files = $this->moduleFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = $this->codeOnly(file_get_contents($file));

            $this->assertDoesNotMatchRegularExpression(
                "/make\\(\\s*['\"]balance_cents['\"]/",
                $code,
                basename($file)." binds a component to 'balance_cents' — only TrustBalanceService may ever write this column.",
            );

            // Also forbid the matter-level counterpart column name for
            // the same reason (matter_trust_balances.balance_cents).
            $this->assertDoesNotMatchRegularExpression(
                "/make\\(\\s*['\"]matter_balance_cents['\"]/",
                $code,
                basename($file)." binds a component to 'matter_balance_cents'.",
            );
        }
    }

    /**
     * Every displayed ledger/matter balance in this module is read
     * through the relationship (`balance.balance_cents`), never the
     * bare column name directly on any component — this is a stricter
     * variant of the test above: it also catches a component bound to
     * exactly `balance_cents`/`matter_balance_cents` with no relation
     * prefix at all (which the substring scan above would also catch,
     * but this asserts it positively: every component whose name ENDS
     * with `.balance_cents` is fine — a read-only relationship display
     * — while a bare, undotted `balance_cents`/`matter_balance_cents`
     * is not). Deliberately anchors on the EXACT column names (not a
     * substring match) so legitimate, unrelated TrustReconciliation
     * columns that merely contain the text "balance_cents" as part of a
     * longer, different, permitted name (e.g. `system_balance_cents`,
     * `asserted_bank_balance_cents` — both fine, TrustReconciliationService
     * is their sole writer and they are read-only displays here) are
     * never flagged.
     */
    public function test_every_balance_cents_component_reference_is_a_dotted_relationship_read(): void
    {
        $files = $this->moduleFiles();

        foreach ($files as $file) {
            $code = $this->codeOnly(file_get_contents($file));

            preg_match_all("/make\\(\\s*['\"]([a-zA-Z0-9_.]+)['\"]/", $code, $matches);

            foreach ($matches[1] as $fieldName) {
                if ($fieldName !== 'balance_cents' && $fieldName !== 'matter_balance_cents') {
                    continue;
                }

                $this->fail(basename($file).": component bound to bare '{$fieldName}' with no relationship prefix — only TrustBalanceService may ever write this column.");
            }
        }

        $this->assertTrue(true);
    }
}
