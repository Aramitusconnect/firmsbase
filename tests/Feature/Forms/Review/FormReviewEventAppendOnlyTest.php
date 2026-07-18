<?php

namespace Tests\Feature\Forms\Review;

use App\Models\Firm;
use App\Models\FormDraft;
use App\Models\FormReviewEvent;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FormReviewEventAppendOnlyTest — required companion proof for
 * App\Models\FormReviewEvent::booted() (added in the same Section
 * 39A-6 Wave 6 batch as form_review_events' own FORCE ROW LEVEL
 * SECURITY activation — see database/migrations/2026_08_27_950032_
 * prepare_row_level_security_and_force_rls_on_form_review_events_table.php's
 * own docblock), mirroring App\Models\EmailSyncEvent's/
 * App\Models\AiApprovalEvent's identical immutability pattern
 * (tests/Feature/Email/Sync/EmailSyncEventAppendOnlyTest.php).
 *
 * Proves two independent things, deliberately kept separate:
 *   (a) Eloquent update()/delete() against an existing row throw
 *       LogicException — the model-layer guard.
 *   (b) A raw DB::table('form_review_events') update/delete (bypassing
 *       Eloquent, and therefore bypassing the model guard entirely)
 *       still SUCCEEDS at the database layer when run under the row's
 *       own firm's context — proving RLS's WITH CHECK clause governs
 *       INSERT-time firm ownership only, and is NOT itself the
 *       append-only enforcement mechanism. The append-only guarantee
 *       comes exclusively from the model guard proven in (a); RLS alone
 *       would happily allow this update/delete.
 */
class FormReviewEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_review_events_has_no_updated_at_column_behavior(): void
    {
        $this->assertFalse(FormReviewEvent::make()->usesTimestamps());
    }

    public function test_updating_an_existing_form_review_event_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('form_review_events is append-only and cannot be updated.');

        $this->runWithFirmContext($firm, fn () => $event->update(['notes' => 'changed']));
    }

    public function test_deleting_an_existing_form_review_event_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('form_review_events is append-only and cannot be deleted.');

        $this->runWithFirmContext($firm, fn () => $event->delete());
    }

    /**
     * RLS alone does NOT deny UPDATE for a row the active session's own
     * firm context legitimately owns — the append-only guarantee is an
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
            return DB::table('form_review_events')->where('id', $event->id)->update(['notes' => 'bypassed eloquent, RLS allowed it']);
        });

        $this->assertSame(
            1,
            $affected,
            'RLS does NOT block a same-firm UPDATE bypassing Eloquent — proving RLS\'s WITH CHECK clause is not the append-only mechanism. Only the model-layer booted() guard enforces append-only behavior.'
        );

        $reRead = $this->runWithFirmContext($firm, fn () => FormReviewEvent::query()->find($event->id));
        $this->assertSame('bypassed eloquent, RLS allowed it', $reRead->notes);
    }

    /**
     * Same proof for DELETE: a raw query-builder delete (bypassing
     * Eloquent) against a row the active session's own firm context
     * legitimately owns succeeds at the database layer — RLS alone does
     * not enforce append-only-ness for DELETE either.
     */
    public function test_raw_query_builder_delete_bypassing_eloquent_still_succeeds_at_the_database_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($event) {
            return DB::table('form_review_events')->where('id', $event->id)->delete();
        });

        $this->assertSame(
            1,
            $affected,
            'RLS does NOT block a same-firm DELETE bypassing Eloquent — proving RLS\'s WITH CHECK clause is not the append-only mechanism. Only the model-layer booted() guard enforces append-only behavior.'
        );

        $reRead = $this->runWithFirmContext($firm, fn () => FormReviewEvent::query()->find($event->id));
        $this->assertNull($reRead, 'The row genuinely no longer exists — RLS alone permitted this delete.');
    }

    private function createEventForFirm(Firm $firm): FormReviewEvent
    {
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $draft = $this->runWithFirmContext($firm, fn () => FormDraft::factory()->forFirmAndMatter($firm, $matter)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => FormReviewEvent::factory()->create([
            'firm_id' => $firm->id,
            'form_draft_id' => $draft->id,
        ]));
    }
}
