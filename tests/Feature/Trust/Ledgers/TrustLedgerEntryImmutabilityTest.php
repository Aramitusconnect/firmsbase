<?php

namespace Tests\Feature\Trust\Ledgers;

use App\Enums\TrustLedgerEntryType;
use App\Models\TrustLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Correction #5 (strict design): trust_ledger_entries has NO status
 * column at all — a posted entry's fields never change, ever. This is
 * the required test proving update()/delete() both throw, and that the
 * ONLY way to represent a correction is a brand-new opposite-signed row
 * via TrustLedgerEntryReversalService, which never touches the
 * original row's own fields.
 */
class TrustLedgerEntryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_existing_entry_throws(): void
    {
        $entry = TrustLedgerEntry::factory()->create();

        $this->expectException(\LogicException::class);
        $entry->update(['amount_cents' => 999999]);
    }

    public function test_deleting_an_existing_entry_throws(): void
    {
        $entry = TrustLedgerEntry::factory()->create();

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_trust_ledger_entries_table_has_no_status_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('trust_ledger_entries', 'status'),
            'trust_ledger_entries must never gain a status column (correction #5, strict immutability design).'
        );
    }

    public function test_entry_type_enum_still_carries_no_status_semantics(): void
    {
        $cases = array_map(fn ($c) => $c->value, TrustLedgerEntryType::cases());

        $this->assertContains('reversal', $cases);
        $this->assertNotContains('posted', $cases);
        $this->assertNotContains('reversed', $cases);
    }
}
