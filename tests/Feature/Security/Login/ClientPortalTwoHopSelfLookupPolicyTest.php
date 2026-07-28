<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Login;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClientPortalTwoHopSelfLookupPolicyTest — Checkpoint 4 ("Plaid
 * financial evidence add-on"), Client Portal authentication foundation.
 *
 * CORRECTED DESIGN: this file originally proved a TWO-HOP RLS bootstrap
 * (Hop 1 — client_portal_users_self_lookup, Hop 2 — clients_self_lookup).
 * Hop 1 no longer exists: `client_portal_users` originally carried
 * FORCE ROW LEVEL SECURITY (a subquery-shaped tenant-isolation policy
 * plus that self-lookup policy), but neither policy permitted
 * `Auth::guard('client')->attempt()`/password-reset's
 * `retrieveByCredentials()` to find a row BY EMAIL with no context at
 * all — the unavoidable first step of any login — making the Client
 * Portal's login and password-reset flows completely non-functional
 * (see ClientPortalAuthenticationTest's own docblock for the full
 * empirical reproduction that caught this). `client_portal_users` has
 * since been reclassified System (no RLS at all, identical treatment to
 * `users` — see that table's own create-migration docblock's "WHY THIS
 * TABLE HAS NO RLS" section), matching the established `users`
 * (no RLS) / `firm_users` (RLS, real membership) split exactly:
 * `client_portal_users` is the credential/identity table, and the real
 * tenant boundary lives one level down, in `Client` and
 * `client_portal_matter_grants`.
 *
 * This file now proves:
 *   - `client_portal_users` can be freely SELECTed by email with NO
 *     context set at all (this is what makes login possible in the
 *     first place) — and that ordinary writes to it require no special
 *     context either, since there is no policy left to satisfy.
 *   - Reading `client_portal_users` alone never grants access to any
 *     other RLS-protected table — the real tenant boundary is enforced
 *     entirely by `clients`' own RLS, unchanged and still correctly
 *     gated (every Hop 2 / `clients_self_lookup` test below is kept
 *     exactly as it was — that part of the design is unchanged and
 *     still correct).
 *   - The full one-hop bootstrap chain `EstablishClientPortalTenantContext`
 *     now performs (ordinary client_portal_users read -> Client
 *     self-lookup -> firm context) correctly resolves a portal user's
 *     own firm and nothing else.
 */
class ClientPortalTwoHopSelfLookupPolicyTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // client_portal_users — no RLS at all (System classification)
    // ================================================================

    public function test_client_portal_users_can_be_read_by_email_with_absolutely_no_context_set(): void
    {
        // This is the exact query shape Auth::attempt()/retrieveByCredentials()
        // must perform, and the exact query the confirmed defect broke:
        // a raw lookup by email with no firm context and no portal-user
        // context of any kind active.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);

        $noContextActive = DB::selectOne("select current_setting('app.current_firm_id', true) as value")->value;
        $this->assertTrue($noContextActive === null || $noContextActive === '', 'This test must genuinely run with no ambient firm context, matching a real unauthenticated login POST.');

        $row = DB::table('client_portal_users')->where('email', $portalUser->email)->first();

        $this->assertNotNull($row, 'client_portal_users must be freely readable by email with no context at all — this is what makes login and password reset possible.');
        $this->assertSame($portalUser->id, $row->id);
    }

    public function test_client_portal_users_select_with_no_context_does_not_leak_rows_across_firms(): void
    {
        // No RLS does not mean "no isolation whatsoever" — it means
        // isolation for this table is enforced by ordinary application
        // logic (email uniqueness, exact WHERE clauses) rather than by
        // a database policy, exactly like the stock `users` table.
        // Proves the query itself still only returns the row asked for.
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $portalUserA = $this->makePortalUser($clientA);
        $portalUserB = $this->makePortalUser($clientB);

        $row = DB::table('client_portal_users')->where('email', $portalUserA->email)->first();

        $this->assertSame($portalUserA->id, $row->id);
        $this->assertNotSame($portalUserB->id, $row->id);
    }

    public function test_reading_a_client_portal_users_row_alone_grants_no_access_to_the_clients_table(): void
    {
        // The load-bearing safety property of the corrected design:
        // client_portal_users carrying no RLS must never be usable to
        // bypass clients' own real tenant isolation. Even after
        // discovering a portal user's row (and its client_id) with no
        // context, reading the corresponding `clients` row with that
        // same no-context session must still fail — the boundary is
        // enforced entirely downstream, by clients' own FORCE RLS.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);

        $row = DB::table('client_portal_users')->where('email', $portalUser->email)->first();
        $this->assertNotNull($row);

        $clientRow = DB::table('clients')->where('id', $row->client_id)->first();

        $this->assertNull($clientRow, "Discovering a client_portal_users row (and its client_id) with no context must NOT unlock the corresponding clients row — that boundary belongs entirely to clients' own RLS.");
    }

    public function test_client_portal_users_permits_ordinary_writes_with_no_special_context(): void
    {
        // With no RLS on this table, ordinary firm-context writes
        // (ClientPortalService::activate()'s own real shape) continue
        // to work exactly as before.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $portalUserId = $this->runWithFirmContext($firm, function () use ($client) {
            $id = DB::table('client_portal_users')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'client_id' => $client->id,
                'email' => $client->email,
                'password' => Hash::make('irrelevant'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('client_portal_users')->where('id', $id)->update(['is_active' => false]);

            return $id;
        });

        $isActive = DB::table('client_portal_users')->where('id', $portalUserId)->value('is_active');
        $this->assertFalse((bool) $isActive, 'Ordinary writes to client_portal_users must remain fully functional now that RLS has been removed.');
    }

    public function test_the_force_rls_migration_for_client_portal_users_no_longer_exists(): void
    {
        $this->assertFileDoesNotExist(
            base_path('database/migrations/2026_09_24_180002_prepare_row_level_security_and_force_rls_on_client_portal_users_table.php'),
            'The FORCE RLS migration for client_portal_users was deleted as part of the corrected design — it should never be reintroduced.'
        );

        $rlsRow = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'client_portal_users'");
        $this->assertFalse((bool) $rlsRow->relrowsecurity, 'client_portal_users must not have row level security enabled.');
        $this->assertFalse((bool) $rlsRow->relforcerowsecurity, 'client_portal_users must not have FORCE row level security enabled.');

        $selfLookupPolicy = DB::selectOne(
            "select 1 as found from pg_policy where polrelid = 'client_portal_users'::regclass and polname = 'client_portal_users_self_lookup'"
        );
        $this->assertNull($selfLookupPolicy, 'client_portal_users_self_lookup must not exist.');

        $tenantIsolationPolicy = DB::selectOne(
            "select 1 as found from pg_policy where polrelid = 'client_portal_users'::regclass and polname = 'client_portal_users_tenant_isolation'"
        );
        $this->assertNull($tenantIsolationPolicy, 'client_portal_users_tenant_isolation must not exist.');
    }

    // ================================================================
    // Hop 2 — clients_self_lookup (unchanged; clients remains
    // BelongsToTenant + FORCE-RLS protected, so this is still the one
    // genuine RLS hop the bootstrap needs)
    // ================================================================

    public function test_client_self_lookup_context_alone_can_read_only_that_clients_own_row(): void
    {
        $firm = Firm::factory()->create();
        $clientOne = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientTwo = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $visibleIds = $tenantContext->withClientSelfLookupContext(
            $clientOne->id,
            fn () => DB::table('clients')->pluck('id')->all(),
        );

        $this->assertContains($clientOne->id, $visibleIds);
        $this->assertNotContains($clientTwo->id, $visibleIds, "A client-self-lookup session must not reveal another client's row, even in the same firm.");
    }

    public function test_client_self_lookup_context_alone_cannot_insert_a_clients_row(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $tenantContext->withClientSelfLookupContext($client->id, function () use ($firm) {
            DB::table('clients')->insert([
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firm->id,
                'display_name' => 'Escalation Attempt',
                'email' => 'escalation@example.com',
                'preferred_language' => 'en',
                'preferred_timezone' => 'America/New_York',
                'portal_status' => ClientPortalStatus::NotInvited->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_client_self_lookup_context_alone_cannot_update_a_clients_row(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Original Name']));

        $tenantContext = new TenantContextService;

        $affected = $tenantContext->withClientSelfLookupContext(
            $client->id,
            fn () => DB::table('clients')->where('id', $client->id)->update(['display_name' => 'Hacked Name']),
        );

        $this->assertSame(0, $affected, 'Client-self-lookup context alone must not be able to update any clients row.');

        $reRead = $this->runWithFirmContext($firm, fn () => DB::table('clients')->where('id', $client->id)->value('display_name'));
        $this->assertSame('Original Name', $reRead, 'The row must be genuinely unchanged, not just reported as 0 rows affected.');
    }

    public function test_client_self_lookup_context_alone_cannot_change_firm_id(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $client = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());

        $tenantContext = new TenantContextService;

        $affected = $tenantContext->withClientSelfLookupContext(
            $client->id,
            fn () => DB::table('clients')->where('id', $client->id)->update(['firm_id' => $firmB->id]),
        );

        $this->assertSame(0, $affected, 'Client-self-lookup context alone must not be able to move a clients row to a different firm.');

        $fresh = $this->runWithFirmContext($firmA, fn () => DB::table('clients')->where('id', $client->id)->first());
        $this->assertSame($firmA->id, $fresh->firm_id);
    }

    public function test_firm_context_can_still_perform_legitimate_writes_on_clients(): void
    {
        $firm = Firm::factory()->create();

        $clientId = $this->runWithFirmContext($firm, function () use ($firm) {
            $id = DB::table('clients')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firm->id,
                'display_name' => 'Legit Client',
                'email' => 'legit@example.com',
                'preferred_language' => 'en',
                'preferred_timezone' => 'America/New_York',
                'portal_status' => ClientPortalStatus::NotInvited->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('clients')->where('id', $id)->update(['display_name' => 'Updated Legit Client']);

            return $id;
        });

        $name = $this->runWithFirmContext($firm, fn () => DB::table('clients')->where('id', $clientId)->value('display_name'));
        $this->assertSame('Updated Legit Client', $name, 'Legitimate firm-context writes to clients must remain fully functional after adding clients_self_lookup.');
    }

    public function test_migration_down_drops_only_the_self_lookup_policy_leaving_tenant_isolation_intact(): void
    {
        $migration = require base_path('database/migrations/2026_09_24_180006_add_self_lookup_clause_to_clients_rls_policy.php');

        $migration->down();

        try {
            $firm = Firm::factory()->create();
            $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

            $selfLookupPolicyExists = DB::selectOne(
                "select 1 as found from pg_policy where polrelid = 'clients'::regclass and polname = 'clients_self_lookup'"
            );
            $this->assertNull($selfLookupPolicyExists, 'down() must drop the clients_self_lookup policy entirely.');

            $tenantIsolationPolicyExists = DB::selectOne(
                "select 1 as found from pg_policy where polrelid = 'clients'::regclass and polname = 'clients_tenant_isolation'"
            );
            $this->assertNotNull($tenantIsolationPolicyExists, "down() must NOT touch clients' own pre-existing tenant-isolation policy.");

            $visibleWithClientSelfLookupOnly = (new TenantContextService)->withClientSelfLookupContext(
                $client->id,
                fn () => DB::table('clients')->where('id', $client->id)->count(),
            );
            $this->assertSame(0, $visibleWithClientSelfLookupOnly, 'With clients_self_lookup dropped, client-self-lookup context alone must no longer reveal any row.');

            $visibleWithFirmContext = $this->runWithFirmContext($firm, fn () => DB::table('clients')->where('id', $client->id)->count());
            $this->assertSame(1, $visibleWithFirmContext, 'Ordinary firm-context reads must be unaffected by dropping the self-lookup policy.');
        } finally {
            $migration->up();
        }

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $visibleAfterReapply = (new TenantContextService)->withClientSelfLookupContext(
            $client->id,
            fn () => DB::table('clients')->where('id', $client->id)->count(),
        );
        $this->assertSame(1, $visibleAfterReapply, 'After up() re-applies the migration, clients_self_lookup must work again.');
    }

    public function test_client_self_lookup_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        (new TenantContextService)->withClientSelfLookupContext($client->id, fn () => 'ok');

        $value = DB::selectOne("select current_setting('app.current_client_id', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_client_id must be cleared after a successful withClientSelfLookupContext() call.');
    }

    public function test_client_self_lookup_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        try {
            (new TenantContextService)->withClientSelfLookupContext($client->id, function () {
                throw new \RuntimeException('simulated failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $value = DB::selectOne("select current_setting('app.current_client_id', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_client_id must be cleared even when the callback throws.');
    }

    public function test_setting_hop_two_directly_to_a_different_clients_id_only_ever_unlocks_that_one_clients_own_row(): void
    {
        // Proves the "lookup scoped to exactly one row" security
        // property directly: even if app code (mistakenly or
        // maliciously) called withClientSelfLookupContext() with some
        // OTHER client's id (rather than one legitimately resolved off
        // an ordinary client_portal_users read), clients_self_lookup
        // only ever unlocks that one exact row — it can never be used
        // to enumerate or bulk-read every client, and it never grants
        // any INSERT/UPDATE/DELETE capability regardless of which id is
        // supplied.
        $firm = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $tenantContext = new TenantContextService;

        $visibleIds = $tenantContext->withClientSelfLookupContext(
            $clientB->id,
            fn () => DB::table('clients')->pluck('id')->all(),
        );

        $this->assertSame([$clientB->id], $visibleIds, 'clients_self_lookup must reveal exactly one row — the id it was scoped to — never more.');
        $this->assertNotContains($clientA->id, $visibleIds);
    }

    // ================================================================
    // The full one-hop chain, end to end — the actual bootstrap
    // sequence EstablishClientPortalTenantContext performs, exercised
    // directly against the live PostgreSQL catalog rather than via
    // Eloquent, and rather than via a real HTTP request.
    // ================================================================

    public function test_the_full_one_hop_chain_correctly_resolves_a_portal_users_own_firm_and_nothing_else(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $portalUser = $this->makePortalUser($clientA);

        $tenantContext = new TenantContextService;

        // Ordinary, unwrapped read — client_portal_users has no RLS to
        // satisfy, exactly what makes this the "no context needed"
        // first step.
        $clientId = DB::table('client_portal_users')->where('id', $portalUser->id)->value('client_id');
        $this->assertNotNull($clientId, 'Resolving client_id off the portal users own row must require no special context at all.');

        $visibleMatterIds = $tenantContext->withClientSelfLookupContext(
            $clientId,
            function () use ($tenantContext, $clientId) {
                // The one remaining genuine RLS hop: resolve this
                // client's own firm_id — the ONLY thing the self-lookup
                // carve-out on clients is meant to unlock.
                $firmId = DB::table('clients')->where('id', $clientId)->value('firm_id');
                $this->assertNotNull($firmId, 'The self-lookup hop must resolve a firm_id from the clients own row.');

                // Activate ordinary firm context using ONLY the value
                // the hop above resolved — never anything
                // request-supplied. Mirrors the real split
                // EstablishClientPortalTenantContext (PHP-memory only,
                // via setFirmContext()) + ApplyTenantDatabaseContext
                // (bridges PHP-memory into the Postgres session
                // setting, via setDatabaseTenantContext()) perform as
                // two separate middleware in the real request pipeline
                // — setFirmContext() alone does NOT touch the Postgres
                // session setting a raw DB::table() query is governed
                // by.
                $tenantContext->setFirmContext($firmId);
                $tenantContext->setDatabaseTenantContext();

                try {
                    return DB::table('matters')->pluck('id')->all();
                } finally {
                    $tenantContext->clearDatabaseTenantContext();
                    $tenantContext->clearFirmContext();
                }
            },
        );

        $this->assertContains($matterA->id, $visibleMatterIds, "The bootstrap chain must correctly activate the portal user's own firm's context.");
        $this->assertNotContains($matterB->id, $visibleMatterIds, "The bootstrap chain must never leak visibility into a different firm's matters.");
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function makePortalUser(Client $client, array $overrides = []): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create(array_merge([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ], $overrides)));
    }
}
