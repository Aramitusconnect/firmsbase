<?php

namespace Tests\Feature\Email\Sync;

use App\Models\EmailAccount;
use App\Models\EmailSyncEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmailSyncEventAppendOnlyTest — required companion proof for
 * App\Models\EmailSyncEvent::booted() (added in the same Section
 * 39A-5 Wave 5 batch as email_sync_events' own FORCE ROW LEVEL
 * SECURITY activation — see database/migrations/2026_08_27_950028_
 * prepare_row_level_security_and_force_rls_on_email_sync_events_table.php's
 * own docblock), mirroring App\Models\AiApprovalEvent's identical
 * immutability pattern (tests/Feature/Ai/Approval/
 * AiApprovalEventAppendOnlyTest.php).
 *
 * Proves two independent things, deliberately kept separate:
 *   (a) Eloquent update()/delete() against an existing row throw
 *       LogicException — the model-layer guard.
 *   (b) A raw DB::table('email_sync_events') update/delete
 *       (bypassing Eloquent, and therefore bypassing the model guard
 *       entirely) still SUCCEEDS at the database layer when run under
 *       the row's own firm's context — proving RLS's WITH CHECK
 *       clause governs INSERT-time firm ownership only, and is NOT
 *       itself the append-only enforcement mechanism. The append-only
 *       guarantee comes exclusively from the model guard proven in
 *       (a); RLS alone would happily allow this update/delete.
 */
class EmailSyncEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_sync_events_has_no_updated_at_column_behavior(): void
    {
        $this->assertFalse(EmailSyncEvent::make()->usesTimestamps());
    }

    public function test_updating_an_existing_email_sync_event_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('email_sync_events is append-only and cannot be updated.');

        $this->runWithFirmContext($firm, fn () => $event->update(['detail' => 'changed']));
    }

    public function test_deleting_an_existing_email_sync_event_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('email_sync_events is append-only and cannot be deleted.');

        $this->runWithFirmContext($firm, fn () => $event->delete());
    }

    /**
     * §8.2-equivalent proof for this table: RLS alone does NOT deny
     * UPDATE for a row the active session's own firm context
     * legitimately owns — the append-only guarantee is an
     * application-layer (model) mechanism, not an RLS one. A raw
     * query-builder update (bypassing Eloquent, whose own booted()
     * guard would throw first regardless — see the two tests above)
     * isolates this proof.
     */
    public function test_raw_query_builder_update_bypassing_eloquent_still_succeeds_at_the_database_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($event) {
            return DB::table('email_sync_events')->where('id', $event->id)->update(['detail' => 'bypassed eloquent, RLS allowed it']);
        });

        $this->assertSame(
            1,
            $affected,
            'RLS does NOT block a same-firm UPDATE bypassing Eloquent — proving RLS\'s WITH CHECK clause is not the append-only mechanism. Only the model-layer booted() guard enforces append-only behavior.'
        );

        $reRead = $this->runWithFirmContext($firm, fn () => EmailSyncEvent::query()->find($event->id));
        $this->assertSame('bypassed eloquent, RLS allowed it', $reRead->detail);
    }

    /**
     * Same proof for DELETE: a raw query-builder delete (bypassing
     * Eloquent) against a row the active session's own firm context
     * legitimately owns succeeds at the database layer — RLS alone
     * does not enforce append-only-ness for DELETE either.
     */
    public function test_raw_query_builder_delete_bypassing_eloquent_still_succeeds_at_the_database_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($event) {
            return DB::table('email_sync_events')->where('id', $event->id)->delete();
        });

        $this->assertSame(
            1,
            $affected,
            'RLS does NOT block a same-firm DELETE bypassing Eloquent — proving RLS\'s WITH CHECK clause is not the append-only mechanism. Only the model-layer booted() guard enforces append-only behavior.'
        );

        $reRead = $this->runWithFirmContext($firm, fn () => EmailSyncEvent::query()->find($event->id));
        $this->assertNull($reRead, 'The row genuinely no longer exists — RLS alone permitted this delete.');
    }

    private function createEventForFirm(Firm $firm): EmailSyncEvent
    {
        $account = $this->runWithFirmContext($firm, fn () => EmailAccount::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => EmailSyncEvent::factory()->create([
            'firm_id' => $firm->id,
            'email_account_id' => $account->id,
        ]));
    }
}
