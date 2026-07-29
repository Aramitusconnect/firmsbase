<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FinancialEvidenceTrustLedgerFirewallTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on"). A
 * structural source-scan proof, mirroring
 * tests/Feature/Trust/Firewall/TrustForbiddenIntegrationsTest.php's own
 * mechanism (glob + string-scan against the real, live files), that
 * NONE of the Financial Evidence detection services, the
 * Review-Queues Livewire UI, the Plaid Filament pages/resources (Firm
 * and Client Portal), the Plaid provider/webhook/controller layer, or
 * the Matter's FinancialEvidenceRelationManager ever writes to
 * TrustLedgerEntry (or any sibling Trust* model/table), or imports
 * anything from app/Services/Trust*.php.
 *
 * Per checkpoint4-design-workspace-and-admin-ui.md §1.7 and the pre-
 * construction inventory's own §4 finding, the Reconciliation
 * Candidates queue is explicitly allowed to perform a READ-ONLY lookup
 * against the TrustLedgerEntry MODEL (never a Trust* SERVICE, never a
 * write) purely for display — this test asserts that narrower,
 * correct boundary rather than a blanket "never mentions TrustLedgerEntry
 * at all" rule, which would be stricter than the design actually
 * requires and would produce a false positive against the one
 * legitimate read.
 *
 * SCAN-SCOPE WIDENING: an earlier version of this test only scanned
 * app/Integrations/Services/FinancialEvidence*.php and
 * app/Livewire/FinancialEvidence/**\/*.php. That left real, live Plaid
 * code — the Firm/Client-Portal Plaid Filament pages and resources,
 * the Plaid provider/webhook/controller layer, and
 * FinancialEvidenceRelationManager — completely invisible to every
 * assertion below. A trust write dropped into any of those files would
 * have passed silently. financialEvidenceApplicationFiles() below now
 * includes all of them.
 *
 * FORBIDDEN-TERM WIDENING: an earlier version matched only the literal
 * class name `TrustLedgerEntry` and a `use App\Services\Trust...`
 * import. That missed the snake_case table name
 * (`trust_ledger_entries`), every sibling Trust* model
 * (TrustAccount, TrustTransferRequest, TrustApprovalEvent,
 * TrustReconciliation, TrustBalance, TrustLedger,
 * TrustChargebackEvent, TrustRefundRequest, MatterTrustBalance), raw
 * `DB::table('trust_...')`/`DB::table('matter_trust_balances')`
 * query-builder writes, and fully-qualified Trust*Service references
 * that never go through a `use` import (e.g.
 * `app(\App\Services\TrustXService::class)`). All of these are now
 * covered.
 */
class FinancialEvidenceTrustLedgerFirewallTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every Trust* MODEL class name that exists in app/Models — the
     * complete, authoritative set (confirmed via `grep -rl '^class
     * Trust' app/Models`). TrustLedgerEntry is listed separately below
     * too since it has its own dedicated, narrower read-only carve-out.
     */
    private const FORBIDDEN_TRUST_MODEL_CLASSES = [
        'TrustLedgerEntry',
        'TrustAccount',
        'TrustTransferRequest',
        'TrustApprovalEvent',
        'TrustReconciliation',
        'TrustBalance',
        'TrustLedger',
        'TrustChargebackEvent',
        'TrustRefundRequest',
        'MatterTrustBalance',
    ];

    /**
     * The exact 10 approved trust tables (mirroring
     * TrustForbiddenIntegrationsTest::APPROVED_10_TABLES) plus
     * matter_trust_balances' model-backing table — every snake_case
     * table name a raw `DB::table(...)` call could target to bypass a
     * class-name-only scan.
     */
    private const FORBIDDEN_TRUST_TABLE_NAMES = [
        'trust_accounts',
        'trust_ledgers',
        'trust_ledger_entries',
        'trust_balances',
        'matter_trust_balances',
        'trust_reconciliations',
        'trust_transfer_requests',
        'trust_refund_requests',
        'trust_approval_events',
        'trust_chargeback_events',
    ];

    /**
     * Method-call shapes that indicate an actual MUTATION (as opposed
     * to a read: ::query()/->get()/->where()/->first()). Includes both
     * Eloquent-shaped writes and raw query-builder writes.
     */
    private const MUTATION_METHOD_SHAPES = [
        '::create(',
        '::query()->create(',
        '::insert(',
        '->save(',
        '->create(',
        '->update(',
        '->delete(',
        '->insert(',
        '->upsert(',
        '->forceDelete(',
    ];

    /**
     * Every detection service + the seven Workspace panels + the three
     * Review-Queue sub-panels + the Firm/Client-Portal Plaid Filament
     * pages and resources + the Plaid provider/webhook/controller
     * layer + FinancialEvidenceRelationManager — the full, closed set
     * of Financial Evidence / Plaid application code this checkpoint's
     * spec requires to never write to the trust ledger.
     */
    private function financialEvidenceApplicationFiles(): array
    {
        return array_values(array_unique(array_merge(
            // Detection services + Livewire Workspace/Review-Queue panels
            // (original scope).
            glob(app_path('Integrations/Services').'/FinancialEvidence*.php') ?: [],
            glob(app_path('Integrations/Services').'/FinancialAccountReclassificationService.php') ?: [],
            glob(app_path('Livewire/FinancialEvidence').'/*.php') ?: [],
            glob(app_path('Livewire/FinancialEvidence/ReviewQueues').'/*.php') ?: [],
            glob(app_path('Livewire/FinancialEvidence/Concerns').'/*.php') ?: [],

            // Firm-panel Plaid pages, resources, and their Pages/RelationManagers.
            glob(app_path('Filament/Firm/Pages').'/Plaid*.php') ?: [],
            glob(app_path('Filament/Firm/Resources').'/Plaid*.php') ?: [],
            glob(app_path('Filament/Firm/Resources/PlaidItemResource').'/*.php') ?: [],
            glob(app_path('Filament/Firm/Resources/PlaidItemResource').'/*/*.php') ?: [],
            glob(app_path('Filament/Firm/Resources/PlaidItemResource').'/*/*/*.php') ?: [],
            glob(app_path('Filament/Firm/Widgets').'/Plaid*.php') ?: [],

            // Client Portal Plaid pages.
            glob(app_path('Filament/ClientPortal/Pages').'/Plaid*.php') ?: [],

            // Platform-level Plaid oversight pages/resources.
            glob(app_path('Filament/Pages').'/Plaid*.php') ?: [],
            glob(app_path('Filament/Resources').'/Plaid*.php') ?: [],
            glob(app_path('Filament/Resources/PlaidItemOversightResource').'/*.php') ?: [],
            glob(app_path('Filament/Resources/PlaidItemOversightResource').'/*/*.php') ?: [],

            // Plaid provider, webhook routing/support, and cost-summary service.
            glob(app_path('Integrations/Providers/Plaid').'/*.php') ?: [],
            glob(app_path('Integrations/Support').'/PlaidItemRoutingService.php') ?: [],
            glob(app_path('Integrations/Services').'/Plaid*.php') ?: [],

            // Client Portal Plaid Link exchange controller.
            glob(app_path('Http/Controllers/ClientPortal').'/Plaid*.php') ?: [],

            // Inbound webhook controller + the Plaid item lifecycle listener
            // it (indirectly) drives.
            glob(app_path('Integrations/Http/Controllers').'/InboundWebhookController.php') ?: [],
            glob(app_path('Integrations/Listeners').'/DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent.php') ?: [],

            // The Matter-panel relation manager that surfaces Financial
            // Evidence records.
            glob(app_path('Filament/Firm/Resources/MatterResource/RelationManagers').'/FinancialEvidenceRelationManager.php') ?: [],
        )));
    }

    public function test_the_scanned_file_set_is_non_empty_so_this_test_cannot_silently_scan_nothing(): void
    {
        $files = $this->financialEvidenceApplicationFiles();

        $this->assertNotEmpty($files, 'Sanity check: the Financial Evidence application file set must not be empty, or every assertion below would vacuously pass.');
        $this->assertGreaterThanOrEqual(30, count($files), 'Expected at least the original detection-service/Livewire set plus the widened Plaid/webhook/relation-manager set.');
    }

    /**
     * Proves the scan-scope gap described in this class's docblock is
     * actually closed: every previously-invisible file the prior
     * review flagged must now appear in the scanned set.
     */
    public function test_the_widened_scan_scope_includes_every_previously_omitted_plaid_and_webhook_file(): void
    {
        $files = array_map('basename', $this->financialEvidenceApplicationFiles());

        $mustBeIncluded = [
            'PlaidReclassificationApprovalsPage.php',
            'PlaidOverviewPage.php',
            'PlaidMatterRequestsPage.php',
            'PlaidUsagePage.php',
            'PlaidUsagePolicyPage.php',
            'PlaidCostAlertsPage.php',
            'PlaidAccountSelectionPage.php',
            'PlaidConsentPage.php',
            'PlaidDateRangeConfirmationPage.php',
            'PlaidRequestReviewPage.php',
            'PlaidUploadFallbackPage.php',
            'PlaidItemResource.php',
            'ListPlaidItems.php',
            'ViewPlaidItem.php',
            'AccountsRelationManager.php',
            'PlaidProvider.php',
            'PlaidExchangeController.php',
            'InboundWebhookController.php',
            'DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent.php',
            'FinancialEvidenceRelationManager.php',
        ];

        foreach ($mustBeIncluded as $expectedFile) {
            $this->assertContains(
                $expectedFile,
                $files,
                "{$expectedFile} must be part of the scanned Financial Evidence / Plaid application file set — this file was previously invisible to this firewall test."
            );
        }
    }

    public function test_no_financial_evidence_file_ever_calls_a_trust_ledger_entry_mutation_method(): void
    {
        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $violations = $this->trustMutationViolationsInFile($file);

            $this->assertSame(
                [],
                $violations,
                basename($file).' must never mutate any Trust* model/table. Violations found: '.implode('; ', $violations)
            );
        }
    }

    /**
     * Strips comment/docblock text (via PHP's own tokenizer, not a
     * naive line-prefix guess) before scanning — several of these
     * files' own docblocks deliberately DISCUSS forbidden method names
     * in prose (e.g. "This service NEVER calls TrustLedgerEntry::create()")
     * to document the boundary for a human reader; a plain
     * string-contains scan over the raw file would false-positive on
     * that exact prose. Real static analysis (and TrustForbiddenIntegrationsTest's
     * own simpler scan, which happens not to hit this collision only
     * because its forbidden strings never appear in any Trust*.php
     * file's own comments) must only ever flag genuine code.
     */
    private function realCodeOnly(string $file): string
    {
        $source = file_get_contents($file);
        $this->assertNotFalse($source);

        return $this->realCodeOnlyFromSource($source);
    }

    private function realCodeOnlyFromSource(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
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

    /**
     * Word-boundary-safe "does this text mention this exact symbol"
     * check. A plain str_contains() would false-positive on genuine
     * substring collisions between two DISTINCT, real symbols in this
     * codebase — e.g. `TrustLedger` (its own model,
     * app/Models/TrustLedger.php) is a literal prefix substring of
     * `TrustLedgerEntry`, and `TrustBalance` (its own model) is a
     * literal substring of `MatterTrustBalance`. Without this guard, a
     * line that legitimately references only `TrustLedgerEntry` would
     * also appear to reference the separate `TrustLedger` symbol, and
     * the "no file may reference any OTHER Trust* model" assertion
     * below would false-positive against the two designated, legitimate
     * read-only files.
     */
    private function containsWholeSymbol(string $haystack, string $symbol): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($symbol, '/').'(?![A-Za-z0-9_])/', $haystack) === 1;
    }

    /**
     * The single, reusable detector behind both the real-file scan
     * (test_no_financial_evidence_file_ever_calls_a_trust_ledger_entry_mutation_method)
     * and the negative/mutation fixture proof
     * (test_the_widened_firewall_actually_catches_a_planted_trust_ledger_mutation)
     * — so the negative test exercises the exact same logic that
     * protects the real files, rather than a parallel implementation
     * that could silently drift out of sync.
     *
     * Flags two independent shapes:
     *   1. A line that combines a forbidden Trust* model class name
     *      (or a forbidden snake_case table name) with a mutation
     *      method shape — e.g. `TrustLedgerEntry::query()->update(...)`
     *      or `trust_ledger_entries` next to `->insert(`.
     *   2. A raw `DB::table('<forbidden-table>')->insert|update|delete|upsert(`
     *      call against any forbidden trust table, regardless of
     *      whether the literal table-name string appears on the same
     *      line as the method call (handles the common
     *      `DB::table('trust_ledger_entries')\n    ->insert([...])`
     *      multi-line style used elsewhere in this codebase).
     *
     * @return list<string> human-readable violation descriptions (empty = clean)
     */
    private function trustMutationViolations(string $code, string $label): array
    {
        $violations = [];

        foreach (explode("\n", $code) as $lineNumber => $line) {
            $mentionsForbiddenTrustSymbol = false;

            foreach (self::FORBIDDEN_TRUST_MODEL_CLASSES as $class) {
                if ($this->containsWholeSymbol($line, $class)) {
                    $mentionsForbiddenTrustSymbol = true;
                    break;
                }
            }

            if (! $mentionsForbiddenTrustSymbol) {
                foreach (self::FORBIDDEN_TRUST_TABLE_NAMES as $table) {
                    if (str_contains($line, $table)) {
                        $mentionsForbiddenTrustSymbol = true;
                        break;
                    }
                }
            }

            if (! $mentionsForbiddenTrustSymbol) {
                continue;
            }

            foreach (self::MUTATION_METHOD_SHAPES as $mutatingShape) {
                if (str_contains($line, $mutatingShape)) {
                    $violations[] = "{$label}:".($lineNumber + 1)." combines a forbidden Trust symbol with mutating shape '{$mutatingShape}'.";
                }
            }
        }

        // Raw query-builder writes against a forbidden trust table,
        // matched across the whole file (not line-by-line) so a
        // fluent, multi-line `DB::table('trust_x')\n->insert([...])`
        // call is still caught even though the table-name literal and
        // the mutating method call sit on different lines.
        if (preg_match_all(
            '/DB::table\(\s*[\'"]([a-z_]+)[\'"]\s*\)(?:\s*->\s*where\([^)]*\))*\s*->\s*(insert|update|delete|upsert)\s*\(/s',
            $code,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $table = $match[1];
                $method = $match[2];

                if (in_array($table, self::FORBIDDEN_TRUST_TABLE_NAMES, true)) {
                    $violations[] = "{$label} contains a raw DB::table('{$table}')->{$method}(...) call against a forbidden trust table.";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    private function trustMutationViolationsInFile(string $file): array
    {
        return $this->trustMutationViolations($this->realCodeOnly($file), basename($file));
    }

    public function test_no_financial_evidence_file_imports_any_trust_domain_service(): void
    {
        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $code = $this->realCodeOnly($file);

            // Catches BOTH a `use App\Services\Trust...Service` import
            // AND a fully-qualified reference with no `use` at all,
            // e.g. `app(\App\Services\TrustXService::class)` or
            // `App\Services\TrustXService::class` used inline — the
            // previous version of this test only matched the `use`
            // form and could be bypassed entirely by fully-qualifying
            // the class name instead of importing it.
            $this->assertDoesNotMatchRegularExpression(
                '/\\\\?App\\\\Services\\\\Trust\w*(Service|Recorder|Policy)/',
                $code,
                basename($file).' must not reference any Trust*Service/Recorder/Policy class from app/Services/Trust*.php, whether via a `use` import or a fully-qualified class reference.'
            );
        }
    }

    /**
     * The one, deliberate, narrow exception: ReconciliationCandidatesQueuePanel
     * and FinancialEvidenceReconciliationCandidateDetectionService are
     * PERMITTED a read-only `TrustLedgerEntry::query()->...` lookup for
     * display — but never `->save()`, `->update(`, or `->delete(`
     * chained off it, and never any `Trust*` SERVICE class reference,
     * and never any OTHER Trust* model/table.
     */
    public function test_the_two_files_that_reference_trust_ledger_entry_do_so_read_only_and_only_those_two(): void
    {
        $filesReferencingTrustLedgerEntry = [];

        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $code = $this->realCodeOnly($file);

            if (str_contains($code, 'TrustLedgerEntry')) {
                $filesReferencingTrustLedgerEntry[] = basename($file);
            }

            $violations = $this->trustMutationViolations($code, basename($file));

            $this->assertSame(
                [],
                $violations,
                basename($file).' must never mutate TrustLedgerEntry or any sibling Trust* model/table. Violations: '.implode('; ', $violations)
            );

            // No file may reference any Trust* model OTHER than
            // TrustLedgerEntry at all — the design only carves out an
            // exception for the ledger-entry read, not for any other
            // trust-domain model. Uses the word-boundary-safe check
            // (not str_contains) because `TrustLedger` is itself a
            // real, distinct model AND a literal prefix substring of
            // `TrustLedgerEntry` — a naive substring check would
            // false-positive on the two files' legitimate
            // TrustLedgerEntry reference.
            foreach (self::FORBIDDEN_TRUST_MODEL_CLASSES as $class) {
                if ($class === 'TrustLedgerEntry') {
                    continue;
                }

                $this->assertFalse(
                    $this->containsWholeSymbol($code, $class),
                    basename($file)." must not reference Trust* model '{$class}' — only a read-only TrustLedgerEntry reference is permitted, and only in the two designated files."
                );
            }
        }

        sort($filesReferencingTrustLedgerEntry);

        $this->assertSame(
            ['FinancialEvidenceReconciliationCandidateDetectionService.php', 'ReconciliationCandidatesQueuePanel.php'],
            $filesReferencingTrustLedgerEntry,
            'Exactly these two files may reference TrustLedgerEntry at all — any other Financial Evidence / Plaid file referencing it is a new, unreviewed coupling to the trust domain.'
        );
    }

    public function test_no_financial_evidence_service_or_panel_file_is_itself_named_trust_star(): void
    {
        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/^Trust/',
                basename($file),
                basename($file).' — a Financial Evidence file must never itself be named Trust*, which would make it invisible to '
                    .'TrustForbiddenIntegrationsTest\'s own glob(app_path(\'Services\').\'/Trust*.php\') scan while actually living outside the trust domain\'s reviewed boundary.'
            );
        }
    }

    public function test_reconciliation_candidate_confirmation_writes_only_to_its_own_table_never_trust_ledger_entries(): void
    {
        $panel = file_get_contents(app_path('Livewire/FinancialEvidence/ReviewQueues/ReconciliationCandidatesQueuePanel.php'));
        $this->assertNotFalse($panel);

        // The one write this panel performs must target
        // FinancialEvidenceReconciliationCandidate, never TrustLedgerEntry
        // or any raw trust table.
        $this->assertStringContainsString('$candidate->update(', $panel);
        $this->assertStringNotContainsString('$ledgerEntry->update(', $panel);
        $this->assertStringNotContainsString('TrustLedgerEntry::query()->update(', $panel);

        foreach (self::FORBIDDEN_TRUST_TABLE_NAMES as $table) {
            $this->assertStringNotContainsString("DB::table('{$table}')", $panel);
            $this->assertStringNotContainsString('DB::table("'.$table.'")', $panel);
        }
    }

    public function test_trust_forbidden_integrations_tests_own_glob_does_not_accidentally_cover_these_files(): void
    {
        // Confirms the premise this whole test class exists to fill:
        // TrustForbiddenIntegrationsTest scans app/Services/Trust*.php
        // only — none of the Financial Evidence detection services
        // (under app/Integrations/Services/), Livewire panels (under
        // app/Livewire/FinancialEvidence/), or the widened Plaid/
        // webhook/relation-manager set fall under that glob, so this
        // separate, mirrored test is the ONLY structural proof covering
        // them.
        $trustGlob = glob(app_path('Services').'/Trust*.php') ?: [];

        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $this->assertNotContains($file, $trustGlob);
        }
    }

    /**
     * NEGATIVE PROOF: demonstrates the widened scan-scope and
     * forbidden-term matching actually detect a real violation shape,
     * rather than merely asserting an empty result that could pass
     * vacuously if the detection logic were broken. This does NOT
     * modify any production file — it writes a throwaway fixture file
     * (created in this test and always removed afterwards, even on
     * assertion failure) at a path that matches this test's own
     * widened Plaid-page glob, containing exactly the violation
     * pattern the prior review's gap report described: a raw
     * `DB::table('trust_ledger_entries')->insert(...)` write dropped
     * into a Plaid page.
     */
    public function test_the_widened_firewall_actually_catches_a_planted_trust_ledger_mutation(): void
    {
        $fixturePath = app_path('Filament/Firm/Pages/PlaidZZZFirewallDetectionProofFixture.php');

        // Defensive cleanup in case a previous interrupted run left the
        // fixture behind.
        if (file_exists($fixturePath)) {
            unlink($fixturePath);
        }

        $violatingSource = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Filament\Firm\Pages;

            use Illuminate\Support\Facades\DB;
            use Filament\Pages\Page;

            /**
             * PlaidZZZFirewallDetectionProofFixture — NOT real production
             * code. A throwaway fixture written and removed by
             * FinancialEvidenceTrustLedgerFirewallTest to prove its
             * widened scan actually catches a planted trust-ledger
             * mutation dropped into a Plaid page. This class is never
             * autoloaded/registered and never executed.
             */
            class PlaidZZZFirewallDetectionProofFixture extends Page
            {
                public function plantedViolation(): void
                {
                    DB::table('trust_ledger_entries')->insert([
                        'amount_cents' => 100,
                    ]);
                }
            }

            PHP;

        try {
            $written = file_put_contents($fixturePath, $violatingSource);
            $this->assertNotFalse($written, 'Failed to write the throwaway firewall-proof fixture file.');

            // 1) Prove the widened GLOB actually covers this path — this
            // is exactly the "Plaid*.php dropped in app/Filament/Firm/Pages"
            // shape the scan-scope gap report described as previously
            // invisible.
            $this->assertContains(
                $fixturePath,
                $this->financialEvidenceApplicationFiles(),
                'The widened financialEvidenceApplicationFiles() glob must include a Plaid*.php file dropped in app/Filament/Firm/Pages/ — this is the exact scan-scope gap this test class exists to close.'
            );

            // 2) Prove the widened FORBIDDEN-TERM matching actually
            // flags the planted raw DB::table('trust_ledger_entries')->insert(...)
            // mutation using the exact same detector the real-file
            // tests rely on.
            $violations = $this->trustMutationViolationsInFile($fixturePath);

            $this->assertNotEmpty(
                $violations,
                'The widened firewall must detect a raw DB::table(\'trust_ledger_entries\')->insert(...) mutation planted in a scanned Plaid page — if this assertion fails, the firewall would silently miss a real trust-ledger write.'
            );

            $this->assertTrue(
                (bool) array_filter($violations, static fn (string $v): bool => str_contains($v, 'trust_ledger_entries') && str_contains($v, 'insert')),
                'Expected a violation specifically naming the trust_ledger_entries table and the insert() call. Got: '.implode('; ', $violations)
            );
        } finally {
            if (file_exists($fixturePath)) {
                unlink($fixturePath);
            }
        }

        $this->assertFileDoesNotExist($fixturePath, 'The throwaway firewall-proof fixture must always be cleaned up, even on assertion failure.');
    }

    /**
     * A second negative proof, at the unit level rather than the
     * filesystem level: confirms the detector flags the sibling
     * Eloquent-shaped mutation (`TrustAccount::query()->update(...)`)
     * against a string fixture, without touching disk at all — the
     * cheaper of the two styles the task allows, kept alongside the
     * filesystem-level proof above because that one is the only way to
     * also prove the GLOB widening (as opposed to just the term
     * matching).
     */
    public function test_the_detector_flags_an_eloquent_shaped_mutation_against_a_sibling_trust_model_string_fixture(): void
    {
        $violatingCode = $this->realCodeOnlyFromSource(<<<'PHP'
            <?php
            // Deliberately-crafted in-memory fixture, never written to disk.
            $account = \App\Models\TrustAccount::query()->where('id', $id)->first();
            \App\Models\TrustAccount::query()->where('id', $id)->update(['status' => 'closed']);
            PHP);

        $violations = $this->trustMutationViolations($violatingCode, 'string-fixture');

        $this->assertNotEmpty(
            $violations,
            'The detector must flag TrustAccount::query()->update(...) even though the read-only TrustAccount::query()->where(...)->first() on the preceding line is legitimate shape-wise — this proves the detector distinguishes reads from mutations rather than banning the symbol outright.'
        );
    }
}
