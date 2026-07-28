<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Data\ResolvedPlaidItemRoute;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * PlaidItemRoutingServiceTest — FirmsVault Live Integrations, Checkpoint 4
 * (Plaid financial evidence add-on) test-writer pass. Proves
 * PlaidItemRoutingService — the sole writer/reader of the new
 * `integration_plaid_item_routes` table
 * (checkpoint4-design-plaid-provider-core.md §11.2;
 * checkpoint4-combined-design.md §1.1.1, binding "Option B";
 * checkpoint4-security-review.md Finding 7, confirmed safe/sufficient) —
 * against the real, just-written production code and a real PostgreSQL
 * database, mirroring `GmailMailboxRoutingServiceTest.php`'s exact
 * structure and rigor (the class's own docblock names that file as its
 * direct structural precedent).
 *
 * Every scenario covered: resolveByItemId() never throws for an unknown
 * item_id; a route never leaks across firms; a second, DIFFERENT
 * connection cannot claim an already-routed item_id (the DB UNIQUE
 * constraint, not merely an app-level check); unroute() then
 * resolveByItemId() returns null; route() called twice for the same
 * connection replaces rather than accumulates; the persisted
 * item_lookup_hmac is proven NOT to equal a plain sha256() of the same
 * input (a regression guard against silently swapping the keyed HMAC for
 * a bare hash); the persisted display ciphertext is proven never to
 * equal or contain the raw item_id; fail-closed behavior when the
 * dedicated HMAC key is not configured.
 */
final class PlaidItemRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_KEY = 'unit-test-plaid-item-routing-hmac-key-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.oauth_apps.plaid.item_routing_hmac_key' => self::HMAC_KEY]);
    }

    // ------------------------------------------------------------
    // resolveByItemId()
    // ------------------------------------------------------------

    public function test_resolve_by_item_id_returns_null_never_throws_for_an_unknown_item_id(): void
    {
        $result = $this->service()->resolveByItemId('nobody-has-ever-routed-this-item-id');

        $this->assertNull($result);
    }

    public function test_resolve_by_item_id_returns_null_for_an_empty_string(): void
    {
        $this->assertNull($this->service()->resolveByItemId(''));
        $this->assertNull($this->service()->resolveByItemId('   '));
    }

    public function test_resolve_by_item_id_returns_the_correct_identity_for_a_routed_item(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'item-sandbox-fixture-id');

        $result = $this->service()->resolveByItemId('item-sandbox-fixture-id');

        $this->assertInstanceOf(ResolvedPlaidItemRoute::class, $result);
        $this->assertSame($firm->id, $result->firmId);
        $this->assertSame($connection->id, $result->firmIntegrationId);
        $this->assertSame($connection->integration_provider_id, $result->integrationProviderId);
    }

    /**
     * Never resolves a route belonging to a different firm — routing two
     * DIFFERENT item_ids to two different firms must never let one
     * firm's lookup leak the other firm's identity.
     */
    public function test_resolve_by_item_id_never_resolves_a_route_belonging_to_a_different_firm(): void
    {
        [$firmA, $connectionA] = $this->makeConnection();
        [$firmB, $connectionB] = $this->makeConnection();

        $this->service()->route($connectionA, 'item-firm-a');
        $this->service()->route($connectionB, 'item-firm-b');

        $resultA = $this->service()->resolveByItemId('item-firm-a');
        $resultB = $this->service()->resolveByItemId('item-firm-b');

        $this->assertSame($firmA->id, $resultA->firmId);
        $this->assertNotSame($firmB->id, $resultA->firmId);

        $this->assertSame($firmB->id, $resultB->firmId);
        $this->assertNotSame($firmA->id, $resultB->firmId);
    }

    /**
     * Unlike GmailMailboxRoutingService's mailbox normalization
     * (trim + lowercase), item_id normalization is deliberately trim-only
     * — Plaid documents item_id as opaque, case-sensitive data, so
     * lower-casing it would risk corrupting a legitimately case-variant
     * value. A leading/trailing-whitespace-padded lookup must still
     * resolve; a mere-case variant must NOT.
     */
    public function test_resolve_by_item_id_normalizes_surrounding_whitespace_only_never_case(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, '  item-Sandbox-Mixed-Case-Id  ');

        $this->assertNotNull($this->service()->resolveByItemId('item-Sandbox-Mixed-Case-Id'), 'A whitespace-padded write must resolve for the trimmed read.');
        $this->assertNull($this->service()->resolveByItemId('item-sandbox-mixed-case-id'), 'item_id is case-sensitive opaque data — a lower-cased lookup must NOT resolve a differently-cased route.');
    }

    // ------------------------------------------------------------
    // route() — the sole writer
    // ------------------------------------------------------------

    public function test_route_throws_on_an_empty_item_id(): void
    {
        [, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->route($connection, '   ');
    }

    /**
     * A second, DIFFERENT connection cannot claim an already-routed
     * item_id: proves the DB UNIQUE constraint on item_lookup_hmac fires,
     * not merely an app-level check.
     */
    public function test_a_second_connection_cannot_route_the_same_item_id_while_the_first_is_active(): void
    {
        [, $connectionA] = $this->makeConnection();
        [, $connectionB] = $this->makeConnection();

        $this->service()->route($connectionA, 'shared-item-id');

        $this->expectException(QueryException::class);

        $this->service()->route($connectionB, 'shared-item-id');
    }

    /**
     * route() called twice for the SAME connection replaces rather than
     * accumulates — delete-before-insert discipline.
     */
    public function test_route_called_twice_for_the_same_connection_replaces_rather_than_accumulates(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'first-item-id');
        $this->service()->route($connection, 'second-item-id');

        $rowCount = DB::table('integration_plaid_item_routes')
            ->where('firm_integration_id', $connection->id)
            ->count();

        $this->assertSame(1, $rowCount, 'A connection must never accumulate more than one resolvable item route.');
        $this->assertNull($this->service()->resolveByItemId('first-item-id'), 'The old route must no longer resolve after a replace.');
        $this->assertNotNull($this->service()->resolveByItemId('second-item-id'));
    }

    /**
     * A repeated route() call to the SAME item_id for the SAME connection
     * must be a safe, idempotent no-op replace, never a unique-constraint
     * violation against itself.
     */
    public function test_route_called_twice_with_the_same_item_id_for_the_same_connection_is_idempotent(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'stable-item-id');
        $this->service()->route($connection, 'stable-item-id');

        $rowCount = DB::table('integration_plaid_item_routes')
            ->where('firm_integration_id', $connection->id)
            ->count();

        $this->assertSame(1, $rowCount);
        $this->assertNotNull($this->service()->resolveByItemId('stable-item-id'));
    }

    /**
     * Regression guard: the persisted item_lookup_hmac must NEVER equal a
     * bare sha256() of the normalized item_id — it must be a KEYED HMAC
     * (checkpoint4-security-review.md Finding 7's conclusion is that a
     * keyed HMAC is "strictly safer than a plain hash... at negligible
     * extra cost," so the code must actually carry that stronger
     * discipline, not merely a lighter, unkeyed hash).
     */
    public function test_the_persisted_lookup_value_is_not_a_plain_sha256_hash(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'item-sandbox-fixture-id');

        $row = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->first();

        $this->assertNotNull($row);
        $this->assertNotSame(
            hash('sha256', 'item-sandbox-fixture-id'),
            $row->item_lookup_hmac,
            'item_lookup_hmac must be a KEYED HMAC, never a plain sha256() of the item_id.'
        );
        $this->assertSame(64, strlen($row->item_lookup_hmac), 'HMAC-SHA256 hex digest must be exactly 64 characters.');
    }

    /**
     * The HMAC must actually be keyed — changing the configured key must
     * change the digest for the identical input, proving the key
     * material is genuinely folded into the computation (not merely a
     * hash('sha256', $key.$itemId) that happened to differ from a bare
     * hash by coincidence of length).
     */
    public function test_the_persisted_lookup_value_changes_when_the_hmac_key_changes(): void
    {
        [, $connectionA] = $this->makeConnection();
        $this->service()->route($connectionA, 'same-item-id-different-key-run');
        $rowUnderKeyA = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connectionA->id)->first();

        // Tear down and re-route under a DIFFERENT key, using a fresh
        // connection (the first item_id is still claimed by connectionA
        // under the old key's hash space).
        config(['integrations.oauth_apps.plaid.item_routing_hmac_key' => 'a-completely-different-hmac-key-0002']);
        [, $connectionB] = $this->makeConnection();
        $this->service()->route($connectionB, 'same-item-id-different-key-run');
        $rowUnderKeyB = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connectionB->id)->first();

        $this->assertNotSame($rowUnderKeyA->item_lookup_hmac, $rowUnderKeyB->item_lookup_hmac, 'The same item_id under two different configured keys must produce two different HMAC digests.');
    }

    /**
     * The display value must be per-firm EmailBodyEncryptionService
     * ciphertext, never the plaintext item_id at rest.
     */
    public function test_the_persisted_display_value_never_equals_or_contains_the_raw_item_id(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'item-sandbox-fixture-id-secret-looking-value');

        $row = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->first();

        $this->assertNotNull($row);
        $this->assertNotSame('item-sandbox-fixture-id-secret-looking-value', $row->item_display_ciphertext);
        $this->assertStringNotContainsString('item-sandbox-fixture-id-secret-looking-value', $row->item_display_ciphertext);
    }

    /**
     * The HMAC key is a fail-closed, required, dedicated secret — a
     * missing/empty configured key must throw rather than silently
     * substitute a weaker value (e.g. APP_KEY).
     */
    public function test_route_throws_when_the_hmac_key_is_not_configured(): void
    {
        config(['integrations.oauth_apps.plaid.item_routing_hmac_key' => null]);
        [, $connection] = $this->makeConnection();

        $this->expectException(RuntimeException::class);

        $this->service()->route($connection, 'item-sandbox-fixture-id');
    }

    public function test_resolve_by_item_id_also_throws_when_the_hmac_key_is_not_configured(): void
    {
        [, $connection] = $this->makeConnection();
        $this->service()->route($connection, 'item-sandbox-fixture-id');

        config(['integrations.oauth_apps.plaid.item_routing_hmac_key' => '']);

        $this->expectException(RuntimeException::class);

        $this->service()->resolveByItemId('item-sandbox-fixture-id');
    }

    // ------------------------------------------------------------
    // unroute() — the sole deleter
    // ------------------------------------------------------------

    public function test_unroute_then_resolve_by_item_id_returns_null(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'item-sandbox-fixture-id');
        $this->assertNotNull($this->service()->resolveByItemId('item-sandbox-fixture-id'));

        $this->service()->unroute($connection);

        $this->assertNull($this->service()->resolveByItemId('item-sandbox-fixture-id'));
    }

    public function test_unroute_is_idempotent_on_a_connection_with_no_mapping(): void
    {
        [, $connection] = $this->makeConnection();

        // No route() call at all — must not throw.
        $this->service()->unroute($connection);

        $this->assertSame(0, DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->count());
    }

    public function test_unroute_only_removes_the_targeted_connections_route(): void
    {
        [, $connectionA] = $this->makeConnection();
        [, $connectionB] = $this->makeConnection();

        $this->service()->route($connectionA, 'item-a');
        $this->service()->route($connectionB, 'item-b');

        $this->service()->unroute($connectionA);

        $this->assertNull($this->service()->resolveByItemId('item-a'));
        $this->assertNotNull($this->service()->resolveByItemId('item-b'), 'unroute() must never remove a DIFFERENT connection\'s route.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function service(): PlaidItemRoutingService
    {
        return app(PlaidItemRoutingService::class);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Plaid->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($provider)
            ->create());

        return [$firm, $connection];
    }
}
