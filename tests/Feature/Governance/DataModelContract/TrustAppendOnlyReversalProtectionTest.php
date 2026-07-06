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

    public function test_trust_ledger_entry_reversal_service_file_was_not_modified_by_section_26(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- app/Services/TrustLedgerEntryReversalService.php'
        ));

        $this->assertSame('', $changed);
    }

    public function test_trust_ledger_entry_model_file_was_not_modified_by_section_26(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- app/Models/TrustLedgerEntry.php'
        ));

        $this->assertSame('', $changed);
    }
}
