<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmIntegrationSerializationSafetyTest — Checkpoint 9 (frozen design
 * §9). Proves `webhook_routing_token` does NOT appear in
 * `FirmIntegration::toArray()`/`toJson()` output even when the
 * attribute is set (the actual fix this checkpoint made: `protected
 * $hidden = ['webhook_routing_token'];`).
 *
 * Also adds an explicit, currently-passing-because-unpopulated
 * assertion documenting that `IntegrationConflict.local_value`/
 * `external_value` WOULD currently survive serialization if populated
 * — a named, intentional, currently-green regression trip-wire (frozen
 * design §9's disclosed residual risk, explicitly OUT OF SCOPE for
 * Checkpoint 9's code fix) — so a future checkpoint populating these
 * fields without adding sanitization makes THIS specific assertion
 * fail, rather than the gap silently persisting unnoticed.
 */
class FirmIntegrationSerializationSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // FirmIntegration.webhook_routing_token — the actual CP9 fix
    // ------------------------------------------------------------

    public function test_webhook_routing_token_is_declared_hidden_on_the_model(): void
    {
        $model = new FirmIntegration;

        $this->assertContains('webhook_routing_token', $model->getHidden());
    }

    public function test_webhook_routing_token_does_not_appear_in_toarray_even_when_set(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->create(['webhook_routing_token' => 'super-secret-routing-token-value']));

        $array = $this->runWithFirmContext($firm, fn () => $connection->fresh()->toArray());

        $this->assertArrayNotHasKey('webhook_routing_token', $array);
    }

    public function test_webhook_routing_token_does_not_appear_in_tojson_even_when_set(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->create(['webhook_routing_token' => 'super-secret-routing-token-value']));

        $json = $this->runWithFirmContext($firm, fn () => $connection->fresh()->toJson());

        $this->assertStringNotContainsString('webhook_routing_token', $json);
        $this->assertStringNotContainsString('super-secret-routing-token-value', $json);
    }

    public function test_direct_attribute_access_to_webhook_routing_token_is_unaffected_by_hidden(): void
    {
        // $hidden only governs toArray()/toJson() serialization, never
        // direct property access — every real caller (e.g.
        // ProviderConnectionService's own disconnect() teardown) still
        // reads/writes the raw attribute directly. Confirmed here so a
        // future reader doesn't mistake $hidden for a broader access
        // restriction than it actually is.
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->create(['webhook_routing_token' => 'direct-access-value']));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());

        $this->assertSame('direct-access-value', $fresh->webhook_routing_token);
    }

    public function test_no_other_column_became_accidentally_hidden(): void
    {
        $model = new FirmIntegration;

        $this->assertSame(['webhook_routing_token'], $model->getHidden(), 'The Checkpoint 9 fix is a ONE-LINE, additive-only change — no other column may be hidden.');
    }

    // ------------------------------------------------------------
    // IntegrationConflict.local_value/external_value — disclosed
    // residual risk, deliberately currently-green trip-wire
    // ------------------------------------------------------------

    public function test_integration_conflict_local_value_and_external_value_are_not_hidden(): void
    {
        // This is NOT an oversight in this test: the frozen design
        // explicitly rules enforcement of this gap OUT OF SCOPE for
        // Checkpoint 9 (no live call site populates these fields
        // today), while requiring the gap to be disclosed as a named
        // residual risk. This assertion documents the model's CURRENT,
        // accepted-for-now shape.
        $model = new IntegrationConflict;

        $this->assertNotContains('local_value', $model->getHidden());
        $this->assertNotContains('external_value', $model->getHidden());
    }

    public function test_integration_conflict_local_value_and_external_value_would_currently_survive_serialization_if_populated(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create([
                'local_value' => ['confidential_field' => 'trip-wire-local-value-marker'],
                'external_value' => ['confidential_field' => 'trip-wire-external-value-marker'],
            ]));

        $array = $this->runWithFirmContext($firm, fn () => $conflict->fresh()->toArray());
        $json = $this->runWithFirmContext($firm, fn () => $conflict->fresh()->toJson());

        // DISCOVERED/DISCLOSED RESIDUAL RISK (frozen design §9, agent-9h
        // §7.2): these two fields currently DO survive ->toArray()/
        // ->toJson() when populated — no SanitizedPayloadReference-
        // equivalent boundary exists yet. This is intentional,
        // documented, currently-green proof of the gap, not a silent
        // omission. A FUTURE checkpoint that builds a live
        // conflict-producing call site MUST add sanitization before
        // that call site goes live — at which point these two
        // assertions should be expected to FLIP (the marker strings
        // should stop appearing), and this test should be updated
        // accordingly rather than left passing on stale assumptions.
        $this->assertArrayHasKey('local_value', $array);
        $this->assertArrayHasKey('external_value', $array);
        $this->assertStringContainsString('trip-wire-local-value-marker', $json);
        $this->assertStringContainsString('trip-wire-external-value-marker', $json);
    }
}
