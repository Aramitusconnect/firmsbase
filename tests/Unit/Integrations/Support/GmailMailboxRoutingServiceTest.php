<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Data\ResolvedGmailMailboxRoute;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\GmailMailboxAlreadyRoutedException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * GmailMailboxRoutingServiceTest — FirmsVault Live Integrations,
 * Checkpoint 3 (test-writer pass). Proves GmailMailboxRoutingService —
 * the sole writer/reader of the new `integration_gmail_mailbox_routes`
 * table (checkpoint3-combined-design.md §5/§6.4;
 * checkpoint3-design-sync-webhooks.md §6.4) — against the real,
 * just-written production code and a real PostgreSQL database, mirroring
 * WebhookConnectionResolverServiceTest.php's direct-against-the-real-
 * service-and-database structure (no HTTP layer involved at all).
 *
 * Every scenario the design's §10.2 security test matrix names is
 * covered here: resolveByMailbox() never throws for an unknown mailbox;
 * a route never leaks across firms; a second connection cannot claim an
 * already-routed mailbox (the DB UNIQUE constraint, not merely an
 * app-level check); unroute() then resolveByMailbox() returns null;
 * route() called twice for the same connection replaces rather than
 * accumulates; the persisted mailbox_lookup_hmac is proven NOT to equal
 * a plain sha256() of the same input (a regression guard against
 * silently swapping the keyed HMAC for a bare hash); the persisted
 * display ciphertext is proven never to equal or contain the raw
 * mailbox string.
 */
final class GmailMailboxRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_KEY = 'unit-test-gmail-mailbox-routing-hmac-key-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => self::HMAC_KEY]);
    }

    // ------------------------------------------------------------
    // resolveByMailbox()
    // ------------------------------------------------------------

    public function test_resolve_by_mailbox_returns_null_never_throws_for_an_unknown_mailbox(): void
    {
        $result = $this->service()->resolveByMailbox('nobody-has-ever-routed-this@example.test');

        $this->assertNull($result);
    }

    public function test_resolve_by_mailbox_returns_null_for_an_empty_string(): void
    {
        $this->assertNull($this->service()->resolveByMailbox(''));
        $this->assertNull($this->service()->resolveByMailbox('   '));
    }

    public function test_resolve_by_mailbox_returns_the_correct_identity_for_a_routed_mailbox(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'attorney@firm-domain.test');

        $result = $this->service()->resolveByMailbox('attorney@firm-domain.test');

        $this->assertInstanceOf(ResolvedGmailMailboxRoute::class, $result);
        $this->assertSame($firm->id, $result->firmId);
        $this->assertSame($connection->id, $result->firmIntegrationId);
        $this->assertSame($connection->integration_provider_id, $result->integrationProviderId);
    }

    /**
     * checkpoint3-design-sync-webhooks.md §10.2 — never resolves a route
     * belonging to a different firm: routing two DIFFERENT mailboxes to
     * two different firms must never let one firm's lookup leak the
     * other firm's identity.
     */
    public function test_resolve_by_mailbox_never_resolves_a_route_belonging_to_a_different_firm(): void
    {
        [$firmA, $connectionA] = $this->makeConnection();
        [$firmB, $connectionB] = $this->makeConnection();

        $this->service()->route($connectionA, 'user-a@firm-a.test');
        $this->service()->route($connectionB, 'user-b@firm-b.test');

        $resultA = $this->service()->resolveByMailbox('user-a@firm-a.test');
        $resultB = $this->service()->resolveByMailbox('user-b@firm-b.test');

        $this->assertSame($firmA->id, $resultA->firmId);
        $this->assertNotSame($firmB->id, $resultA->firmId);

        $this->assertSame($firmB->id, $resultB->firmId);
        $this->assertNotSame($firmA->id, $resultB->firmId);
    }

    /**
     * Normalization (trim + lowercase) is applied to BOTH the write and
     * the read side, so a case-variant or whitespace-padded mailbox
     * string always resolves the same row.
     */
    public function test_resolve_by_mailbox_normalizes_case_and_whitespace(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, '  Attorney@Firm-Domain.TEST  ');

        $result = $this->service()->resolveByMailbox('attorney@firm-domain.test');

        $this->assertNotNull($result);
        $this->assertSame($connection->id, $result->firmIntegrationId);
    }

    // ------------------------------------------------------------
    // route() — the sole writer
    // ------------------------------------------------------------

    public function test_route_throws_on_an_empty_mailbox_address(): void
    {
        [, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->route($connection, '   ');
    }

    /**
     * checkpoint3-design-sync-webhooks.md §10.2 — a second, DIFFERENT
     * connection cannot claim an already-routed mailbox: proves the DB
     * UNIQUE constraint on mailbox_lookup_hmac fires, not merely an
     * app-level check.
     */
    public function test_a_second_connection_cannot_route_the_same_mailbox_while_the_first_is_active(): void
    {
        [, $connectionA] = $this->makeConnection();
        [, $connectionB] = $this->makeConnection();

        $this->service()->route($connectionA, 'shared-mailbox@firm-domain.test');

        // CHECKPOINT 8.2 (§A7b): this used to surface as a raw
        // QueryException from the unique index. The claim is now an
        // explicit INSERT ... ON CONFLICT DO NOTHING made BEFORE the
        // provider call, so a losing claim is reported as a typed,
        // catchable refusal instead of a database error — which is what
        // lets ProviderConnectionService classify it as a DEFINITE failure
        // (nothing happened at the provider) rather than an ambiguous one.
        //
        // Strictly stronger than the previous expectation: it also pins
        // WHICH connection lost and WHICH one owns the mailbox.
        try {
            $this->service()->route($connectionB, 'shared-mailbox@firm-domain.test');
            $this->fail('A second connection must never be able to claim an already-routed mailbox.');
        } catch (GmailMailboxAlreadyRoutedException $e) {
            $this->assertSame((int) $connectionB->id, $e->requestedFirmIntegrationId);
            $this->assertSame((int) $connectionA->id, $e->owningFirmIntegrationId);
        }

        // The first connection's route is untouched by the refusal.
        $this->assertSame(
            1,
            DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connectionA->id)->count()
        );
        $this->assertSame(
            0,
            DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connectionB->id)->count()
        );
    }

    /**
     * route() called twice for the SAME connection replaces rather than
     * accumulates — delete-before-insert discipline, mirroring
     * enableWebhookRouting()'s own "never updateOrCreate()" convention.
     */
    public function test_route_called_twice_for_the_same_connection_replaces_rather_than_accumulates(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'first-mailbox@firm-domain.test');
        $this->service()->route($connection, 'second-mailbox@firm-domain.test');

        $rowCount = DB::table('integration_gmail_mailbox_routes')
            ->where('firm_integration_id', $connection->id)
            ->count();

        $this->assertSame(1, $rowCount, 'A connection must never accumulate more than one resolvable mailbox mapping.');

        $this->assertNull($this->service()->resolveByMailbox('first-mailbox@firm-domain.test'), 'The old route must no longer resolve after a replace.');
        $this->assertNotNull($this->service()->resolveByMailbox('second-mailbox@firm-domain.test'));
    }

    /**
     * A repeated route() call to the SAME mailbox for the SAME
     * connection must be a safe, idempotent no-op replace, never a
     * unique-constraint violation against itself (delete-then-insert, not
     * insert-if-absent).
     */
    public function test_route_called_twice_with_the_same_mailbox_for_the_same_connection_is_idempotent(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'stable-mailbox@firm-domain.test');
        $this->service()->route($connection, 'stable-mailbox@firm-domain.test');

        $rowCount = DB::table('integration_gmail_mailbox_routes')
            ->where('firm_integration_id', $connection->id)
            ->count();

        $this->assertSame(1, $rowCount);
        $this->assertNotNull($this->service()->resolveByMailbox('stable-mailbox@firm-domain.test'));
    }

    /**
     * checkpoint3-design-sync-webhooks.md §6.4.2 — a Gmail mailbox
     * address is a small, structured, guessable string; a PLAIN sha256()
     * of it would be trivially dictionary-attackable offline. Regression
     * guard: the persisted mailbox_lookup_hmac must NEVER equal a bare
     * sha256() of the normalized mailbox — it must be a KEYED HMAC.
     */
    public function test_the_persisted_lookup_value_is_not_a_plain_sha256_hash(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'attorney@firm-domain.test');

        $row = DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->first();

        $this->assertNotNull($row);
        $this->assertNotSame(
            hash('sha256', 'attorney@firm-domain.test'),
            $row->mailbox_lookup_hmac,
            'mailbox_lookup_hmac must be a KEYED HMAC, never a plain sha256() of the mailbox — a plain hash of a small, structured, guessable string is trivially dictionary-attackable offline.'
        );
        $this->assertSame(64, strlen($row->mailbox_lookup_hmac), 'HMAC-SHA256 hex digest must be exactly 64 characters.');
    }

    /**
     * checkpoint3-design-sync-webhooks.md §6.4.2 — the display value must
     * be per-firm EmailBodyEncryptionService ciphertext, never the
     * plaintext mailbox address at rest.
     */
    public function test_the_persisted_display_value_never_equals_or_contains_the_raw_mailbox(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'attorney@firm-domain.test');

        $row = DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->first();

        $this->assertNotNull($row);
        $this->assertNotSame('attorney@firm-domain.test', $row->mailbox_display_ciphertext);
        $this->assertStringNotContainsString('attorney@firm-domain.test', $row->mailbox_display_ciphertext);
        $this->assertStringNotContainsString('attorney', $row->mailbox_display_ciphertext);
    }

    /**
     * The HMAC key is a fail-closed, required, dedicated secret — a
     * missing/empty configured key must throw rather than silently
     * substitute a weaker value (e.g. APP_KEY).
     */
    public function test_route_throws_when_the_hmac_key_is_not_configured(): void
    {
        config(['integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => null]);
        [, $connection] = $this->makeConnection();

        $this->expectException(RuntimeException::class);

        $this->service()->route($connection, 'attorney@firm-domain.test');
    }

    public function test_resolve_by_mailbox_also_throws_when_the_hmac_key_is_not_configured(): void
    {
        [, $connection] = $this->makeConnection();
        $this->service()->route($connection, 'attorney@firm-domain.test');

        config(['integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => '']);

        $this->expectException(RuntimeException::class);

        $this->service()->resolveByMailbox('attorney@firm-domain.test');
    }

    // ------------------------------------------------------------
    // unroute() — the sole deleter
    // ------------------------------------------------------------

    public function test_unroute_then_resolve_by_mailbox_returns_null(): void
    {
        [, $connection] = $this->makeConnection();

        $this->service()->route($connection, 'attorney@firm-domain.test');
        $this->assertNotNull($this->service()->resolveByMailbox('attorney@firm-domain.test'));

        $this->service()->unroute($connection);

        $this->assertNull($this->service()->resolveByMailbox('attorney@firm-domain.test'));
    }

    public function test_unroute_is_idempotent_on_a_connection_with_no_mapping(): void
    {
        [, $connection] = $this->makeConnection();

        // No route() call at all — must not throw.
        $this->service()->unroute($connection);

        $this->assertSame(0, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count());
    }

    public function test_unroute_only_removes_the_targeted_connections_route(): void
    {
        [, $connectionA] = $this->makeConnection();
        [, $connectionB] = $this->makeConnection();

        $this->service()->route($connectionA, 'mailbox-a@firm-domain.test');
        $this->service()->route($connectionB, 'mailbox-b@firm-domain.test');

        $this->service()->unroute($connectionA);

        $this->assertNull($this->service()->resolveByMailbox('mailbox-a@firm-domain.test'));
        $this->assertNotNull($this->service()->resolveByMailbox('mailbox-b@firm-domain.test'), 'unroute() must never remove a DIFFERENT connection\'s route.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function service(): GmailMailboxRoutingService
    {
        return app(GmailMailboxRoutingService::class);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = IntegrationProvider::query()->where('code', ProviderKey::GoogleWorkspace->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::GoogleWorkspace->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($provider)
            ->create());

        return [$firm, $connection];
    }
}
