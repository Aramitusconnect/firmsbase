<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Models\TrustLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TrustAppendOnlyReversalProtectionTest — regression test proving
 * Section 26 did not touch TrustLedgerEntry's append-only guard or the
 * reversal-via-new-row mechanism in any way.
 */
class TrustAppendOnlyReversalProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_ledger_entry_still_blocks_update(): void
    {
        $entry = TrustLedgerEntry::factory()->create();

        $this->expectException(\LogicException::class);
        $entry->update(['amount_cents' => 999]);
    }

    public function test_trust_ledger_entry_still_blocks_delete(): void
    {
        $entry = TrustLedgerEntry::factory()->create();

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_trust_ledger_entry_reversal_service_class_still_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\TrustLedgerEntryReversalService::class));
    }

    /**
     * Wave 10 (trust accounting domain FORCE RLS rollout) is a real,
     * separately-authorized, later change to this exact file — it
     * collapsed reverse()'s pre-existing decoy-wrap pattern into one
     * whole-method runWithFirmContext() wrap (also moving the
     * already-reversed duplicate check inside it), consistent with
     * the same allowance already recorded, by exact path, in
     * DataModelContractFirewallTest::PROTECTED_FILES' own
     * $section39bAllowed list. This is not a weakening of Section 26's
     * original guarantee (Section 26 itself still never touched this
     * file — Wave 10 did, much later, on purpose) — the assertion
     * below still fails if this file is modified for any OTHER,
     * unaccounted-for reason.
     */
    public function test_trust_ledger_entry_reversal_service_file_was_not_modified_by_section_26(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- app/Services/TrustLedgerEntryReversalService.php'
        ));

        $this->assertContains(
            $changed,
            ['', 'app/Services/TrustLedgerEntryReversalService.php'],
            'app/Services/TrustLedgerEntryReversalService.php must either be untouched (Section 26\'s original guarantee) '.
            'or modified only by the explicitly-authorized Wave 10 trust-domain RLS activation — not by anything else.'
        );
    }

    public function test_trust_ledger_entry_model_file_was_not_modified_by_section_26(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- app/Models/TrustLedgerEntry.php'
        ));

        $this->assertSame('', $changed);
    }
}
