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
 * NONE of the Financial Evidence detection services or the
 * Review-Queues Livewire UI ever writes to TrustLedgerEntry or imports
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
 */
class FinancialEvidenceTrustLedgerFirewallTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every detection service + the seven Workspace panels + the three
     * Review-Queue sub-panels — the full, closed set of Financial
     * Evidence application code this checkpoint's spec requires to
     * never write to the trust ledger.
     */
    private function financialEvidenceApplicationFiles(): array
    {
        return array_merge(
            glob(app_path('Integrations/Services').'/FinancialEvidence*.php') ?: [],
            glob(app_path('Integrations/Services').'/FinancialAccountReclassificationService.php') ?: [],
            glob(app_path('Livewire/FinancialEvidence').'/*.php') ?: [],
            glob(app_path('Livewire/FinancialEvidence/ReviewQueues').'/*.php') ?: [],
            glob(app_path('Livewire/FinancialEvidence/Concerns').'/*.php') ?: [],
        );
    }

    public function test_the_scanned_file_set_is_non_empty_so_this_test_cannot_silently_scan_nothing(): void
    {
        $files = $this->financialEvidenceApplicationFiles();

        $this->assertNotEmpty($files, 'Sanity check: the Financial Evidence application file set must not be empty, or every assertion below would vacuously pass.');
        $this->assertGreaterThanOrEqual(10, count($files), 'Expected at least the four detection services + seven Workspace panels + three review-queue sub-panels + the access-gate trait.');
    }

    public function test_no_financial_evidence_file_ever_calls_a_trust_ledger_entry_mutation_method(): void
    {
        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $code = $this->realCodeOnly($file);

            foreach (['TrustLedgerEntry::create(', 'TrustLedgerEntry::query()->create(', 'TrustLedgerEntry::insert('] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    basename($file)." must never mutate TrustLedgerEntry (found '{$needle}')."
                );
            }
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

    public function test_no_financial_evidence_file_imports_any_trust_domain_service(): void
    {
        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $code = $this->realCodeOnly($file);

            $this->assertDoesNotMatchRegularExpression(
                '/use App\\\\Services\\\\Trust\w*(Service|Recorder|Policy)/',
                $code,
                basename($file).' must not import any Trust*Service/Recorder/Policy class from app/Services/Trust*.php.'
            );
        }
    }

    /**
     * The one, deliberate, narrow exception: ReconciliationCandidatesQueuePanel
     * and FinancialEvidenceReconciliationCandidateDetectionService are
     * PERMITTED a read-only `TrustLedgerEntry::query()->...` lookup for
     * display — but never `->save()`, `->update(`, or `->delete(`
     * chained off it, and never any `Trust*` SERVICE class reference.
     */
    public function test_the_two_files_that_reference_trust_ledger_entry_do_so_read_only_and_only_those_two(): void
    {
        $filesReferencingTrustLedgerEntry = [];

        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $code = $this->realCodeOnly($file);

            if (str_contains($code, 'TrustLedgerEntry')) {
                $filesReferencingTrustLedgerEntry[] = basename($file);

                foreach (['TrustLedgerEntry::query()->update(', 'TrustLedgerEntry::query()->delete(', '->update([', '->delete()'] as $mutatingShape) {
                    // Only flag if the mutating shape appears on a line
                    // that ALSO mentions TrustLedgerEntry — a bare
                    // ->update([...]) elsewhere in the same file (e.g.
                    // on this checkpoint's OWN tables) is legitimate and
                    // must not false-positive this assertion.
                    foreach (explode("\n", $code) as $lineNumber => $line) {
                        if (str_contains($line, 'TrustLedgerEntry') && str_contains($line, $mutatingShape)) {
                            $this->fail("{$file}:{$lineNumber} combines TrustLedgerEntry with a mutating shape ('{$mutatingShape}') — this must be read-only.");
                        }
                    }
                }
            }
        }

        sort($filesReferencingTrustLedgerEntry);

        $this->assertSame(
            ['FinancialEvidenceReconciliationCandidateDetectionService.php', 'ReconciliationCandidatesQueuePanel.php'],
            $filesReferencingTrustLedgerEntry,
            'Exactly these two files may reference TrustLedgerEntry at all — any other Financial Evidence file referencing it is a new, unreviewed coupling to the trust domain.'
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
        // FinancialEvidenceReconciliationCandidate, never TrustLedgerEntry.
        $this->assertStringContainsString('$candidate->update(', $panel);
        $this->assertStringNotContainsString('$ledgerEntry->update(', $panel);
        $this->assertStringNotContainsString('TrustLedgerEntry::query()->update(', $panel);
    }

    public function test_trust_forbidden_integrations_tests_own_glob_does_not_accidentally_cover_these_files(): void
    {
        // Confirms the premise this whole test class exists to fill:
        // TrustForbiddenIntegrationsTest scans app/Services/Trust*.php
        // only — none of the Financial Evidence detection services
        // (under app/Integrations/Services/) or Livewire panels (under
        // app/Livewire/FinancialEvidence/) fall under that glob, so this
        // separate, mirrored test is the ONLY structural proof covering
        // them.
        $trustGlob = glob(app_path('Services').'/Trust*.php') ?: [];

        foreach ($this->financialEvidenceApplicationFiles() as $file) {
            $this->assertNotContains($file, $trustGlob);
        }
    }
}
