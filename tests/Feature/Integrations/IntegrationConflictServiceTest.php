<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Services\IntegrationConflictService;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationConflictServiceTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §8;
 * agent-6f-mapping-conflict-design.md §5.2-§5.4;
 * reviews/checkpoint-06/diff-review.md Residual 4). Two halves:
 *
 * 1. The 5 migration-level CHECK constraints, exercised via RAW DB
 *    writes that bypass IntegrationConflictService entirely — these are
 *    the PRIMARY, DB-enforced safety mechanism and cannot be bypassed
 *    by any application code.
 * 2. Direct unit-level tests of IntegrationConflictService::transitionStatus()
 *    itself. Per diff-review.md's Residual 4 resolution: this method has
 *    no production caller yet (Checkpoint 10/11 resolution-workflow
 *    scope), but it must still be correctly tested — the diff review's
 *    confidence that it is "redundant-but-correct, not weaker" than the
 *    DB CHECK constraints rests on this method actually being exercised
 *    here, not merely on it being unreachable in production today.
 */
class IntegrationConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationConflictService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationConflictService();
    }

    // ------------------------------------------------------------
    // Constraint 1: integration_conflicts_resolution_requires_actor
    // ------------------------------------------------------------

    public function test_check_constraint_rejects_a_resolved_status_with_no_resolving_actor(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            $this->rawInsert($firm, $connection, [
                'status' => 'resolved_local_wins',
                'resolved_by_firm_user_id' => null,
                'resolved_at' => null,
            ]);
        });
    }

    public function test_check_constraint_accepts_a_resolved_status_with_actor_and_timestamp_present(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $resolver = $this->firmUserFor($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection, $resolver) {
            return $this->rawInsert($firm, $connection, [
                'status' => 'resolved_local_wins',
                'resolved_by_firm_user_id' => $resolver->id,
                'resolved_at' => now(),
            ]);
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // Constraint 2: integration_conflicts_privileged_resource_dual_approval
    // ------------------------------------------------------------

    public function test_check_constraint_rejects_a_privileged_resource_resolution_with_no_approver(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $resolver = $this->firmUserFor($firm);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $resolver) {
            $this->rawInsert($firm, $connection, [
                'resource_type' => 'document',
                'requires_manual_review' => true,
                'status' => 'resolved_local_wins',
                'resolved_by_firm_user_id' => $resolver->id,
                'resolved_at' => now(),
                'resolution_approved_by_firm_user_id' => null,
            ]);
        });
    }

    public function test_check_constraint_rejects_a_privileged_resource_resolution_where_approver_equals_resolver(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $resolver = $this->firmUserFor($firm);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $resolver) {
            $this->rawInsert($firm, $connection, [
                'resource_type' => 'document',
                'requires_manual_review' => true,
                'status' => 'resolved_local_wins',
                'resolved_by_firm_user_id' => $resolver->id,
                'resolved_at' => now(),
                'resolution_approved_by_firm_user_id' => $resolver->id,
            ]);
        });
    }

    public function test_check_constraint_accepts_a_privileged_resource_resolution_with_two_distinct_actors(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $resolver = $this->firmUserFor($firm);
        $approver = $this->firmUserFor($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection, $resolver, $approver) {
            return $this->rawInsert($firm, $connection, [
                'resource_type' => 'document',
                'requires_manual_review' => true,
                'status' => 'resolved_local_wins',
                'resolved_by_firm_user_id' => $resolver->id,
                'resolved_at' => now(),
                'resolution_approved_by_firm_user_id' => $approver->id,
            ]);
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // Constraint 3: integration_conflicts_flagged_dual_approval
    // ------------------------------------------------------------

    public function test_check_constraint_rejects_a_flagged_non_privileged_resolution_with_no_approver(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $resolver = $this->firmUserFor($firm);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $resolver) {
            $this->rawInsert($firm, $connection, [
                'resource_type' => 'contact',
                'requires_manual_review' => true,
                'status' => 'ignored',
                'resolved_by_firm_user_id' => $resolver->id,
                'resolved_at' => now(),
                'resolution_approved_by_firm_user_id' => null,
            ]);
        });
    }

    public function test_check_constraint_accepts_a_flagged_non_privileged_resolution_with_two_distinct_actors(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $resolver = $this->firmUserFor($firm);
        $approver = $this->firmUserFor($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection, $resolver, $approver) {
            return $this->rawInsert($firm, $connection, [
                'resource_type' => 'contact',
                'requires_manual_review' => true,
                'status' => 'ignored',
                'resolved_by_firm_user_id' => $resolver->id,
                'resolved_at' => now(),
                'resolution_approved_by_firm_user_id' => $approver->id,
            ]);
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // Constraint 4: integration_conflicts_flag_matches_resource_type
    // ------------------------------------------------------------

    public function test_check_constraint_rejects_a_privileged_resource_type_with_requires_manual_review_false(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            $this->rawInsert($firm, $connection, [
                'resource_type' => 'payment',
                'requires_manual_review' => false,
                'status' => 'detected',
            ]);
        });
    }

    public function test_check_constraint_accepts_a_non_privileged_resource_type_with_requires_manual_review_false(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            return $this->rawInsert($firm, $connection, [
                'resource_type' => 'contact',
                'requires_manual_review' => false,
                'status' => 'detected',
            ]);
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // Constraint 5: integration_conflicts_no_silent_expiry_when_flagged
    // ------------------------------------------------------------

    public function test_check_constraint_rejects_expired_status_on_a_flagged_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            $this->rawInsert($firm, $connection, [
                'resource_type' => 'contact',
                'requires_manual_review' => true,
                'status' => 'expired',
            ]);
        });
    }

    public function test_check_constraint_accepts_expired_status_on_an_unflagged_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            return $this->rawInsert($firm, $connection, [
                'resource_type' => 'contact',
                'requires_manual_review' => false,
                'status' => 'expired',
            ]);
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // IntegrationConflictService::transitionStatus() — legal transitions
    // ------------------------------------------------------------

    public function test_transition_status_allows_detected_to_awaiting_review_with_no_actor_required(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'contact']);

        $updated = $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($conflict, ConflictStatus::AwaitingReview));

        $this->assertSame(ConflictStatus::AwaitingReview, $updated->status);
    }

    public function test_transition_status_allows_a_non_privileged_unflagged_resolution_with_a_single_actor(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'contact', 'requires_manual_review' => false]);
        $resolver = $this->firmUserFor($firm);

        $updated = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->transitionStatus($conflict, ConflictStatus::ResolvedLocalWins, $resolver->id),
        );

        $this->assertSame(ConflictStatus::ResolvedLocalWins, $updated->status);
        $this->assertSame($resolver->id, $updated->resolved_by_firm_user_id);
        $this->assertNotNull($updated->resolved_at);
    }

    public function test_transition_status_allows_expiry_on_an_unflagged_conflict(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'contact', 'requires_manual_review' => false]);

        $updated = $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($conflict, ConflictStatus::Expired));

        $this->assertSame(ConflictStatus::Expired, $updated->status);
    }

    public function test_transition_status_allows_a_privileged_resolution_with_two_distinct_actors(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'document', 'requires_manual_review' => true]);
        $resolver = $this->firmUserFor($firm);
        $approver = $this->firmUserFor($firm);

        $updated = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->transitionStatus($conflict, ConflictStatus::ResolvedLocalWins, $resolver->id, $approver->id),
        );

        $this->assertSame(ConflictStatus::ResolvedLocalWins, $updated->status);
        $this->assertSame($resolver->id, $updated->resolved_by_firm_user_id);
        $this->assertSame($approver->id, $updated->resolution_approved_by_firm_user_id);
    }

    // ------------------------------------------------------------
    // IntegrationConflictService::transitionStatus() — illegal
    // transitions, including the same actor-distinctness rule the DB
    // CHECK constraints enforce (redundant-but-correct).
    // ------------------------------------------------------------

    public function test_transition_status_rejects_transitioning_a_non_open_conflict(): void
    {
        // Test-fixture fix (checkpoint-06 verification pass): this
        // fixture must create a conflict that is GENUINELY already
        // resolved (non-open), so transitionStatus() is exercised
        // against a real not-currently-open row. Simply setting
        // status => ResolvedLocalWins without also supplying
        // resolved_by_firm_user_id/resolved_at (as this test previously
        // did) violates the migration's own
        // integration_conflicts_resolution_requires_actor CHECK
        // constraint at INSERT time, so the fixture setup itself would
        // throw a QueryException before transitionStatus() is ever
        // reached — never exercising the "not currently open" guard
        // this test claims to prove.
        $firm = Firm::factory()->create();
        $resolver = $this->firmUserFor($firm);
        $conflict = $this->conflictFor($firm, [
            'resource_type' => 'contact',
            'requires_manual_review' => false,
            'status' => ConflictStatus::ResolvedLocalWins->value,
            'resolved_by_firm_user_id' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not currently open/');

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($conflict, ConflictStatus::Ignored, 1));
    }

    public function test_transition_status_rejects_silent_expiry_on_a_flagged_conflict(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'contact', 'requires_manual_review' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot silently expire/');

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($conflict, ConflictStatus::Expired));
    }

    public function test_transition_status_rejects_a_resolution_with_no_resolving_actor(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'contact', 'requires_manual_review' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot resolve without resolved_by_firm_user_id/');

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($conflict, ConflictStatus::ResolvedLocalWins));
    }

    public function test_transition_status_rejects_a_privileged_resolution_with_no_approver(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'invoice', 'requires_manual_review' => true]);
        $resolver = $this->firmUserFor($firm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a distinct, non-null resolution_approved_by_firm_user_id/');

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($conflict, ConflictStatus::ResolvedLocalWins, $resolver->id));
    }

    public function test_transition_status_rejects_a_privileged_resolution_where_approver_equals_resolver(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'invoice', 'requires_manual_review' => true]);
        $resolver = $this->firmUserFor($firm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a distinct, non-null resolution_approved_by_firm_user_id/');

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service->transitionStatus($conflict, ConflictStatus::ResolvedLocalWins, $resolver->id, $resolver->id),
        );
    }

    public function test_transition_status_rejects_a_flagged_non_privileged_resolution_where_approver_equals_resolver(): void
    {
        $firm = Firm::factory()->create();
        $conflict = $this->conflictFor($firm, ['resource_type' => 'contact', 'requires_manual_review' => true]);
        $resolver = $this->firmUserFor($firm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a distinct, non-null resolution_approved_by_firm_user_id/');

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service->transitionStatus($conflict, ConflictStatus::Ignored, $resolver->id, $resolver->id),
        );
    }

    // ------------------------------------------------------------
    // recordDetection() — idempotent open-conflict path (§6 item 7)
    // ------------------------------------------------------------

    public function test_record_detection_is_idempotent_for_the_same_open_local_record(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $first = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordDetection($connection, 'contact', 'App\\Models\\Contact', 5, 'field_value_mismatch'),
        );

        $second = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordDetection($connection, 'contact', 'App\\Models\\Contact', 5, 'field_value_mismatch'),
        );

        $this->assertSame($first->id, $second->id);

        $count = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationConflict::query()->where('local_id', 5)->count(),
        );
        $this->assertSame(1, $count);
    }

    public function test_record_detection_forces_requires_manual_review_for_privileged_resource_types(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $conflict = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordDetection($connection, 'invoice', 'App\\Models\\Invoice', 9, 'field_value_mismatch', requiresManualReview: false),
        );

        $this->assertTrue($conflict->requires_manual_review, 'requires_manual_review must be forced true for a privileged resource_type regardless of the caller-supplied value.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function firmUserFor(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());
    }

    private function conflictFor(Firm $firm, array $attributes = []): IntegrationConflict
    {
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        return $this->runWithFirmContext(
            $firm,
            fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create($attributes),
        );
    }

    private function rawInsert(Firm $firm, FirmIntegration $connection, array $overrides): bool
    {
        return DB::table('integration_conflicts')->insert(array_merge([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'resource_type' => 'contact',
            'local_type' => 'App\\Models\\Contact',
            'local_id' => random_int(1, 1000000),
            'conflict_type' => 'field_value_mismatch',
            'status' => 'detected',
            'requires_manual_review' => false,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
