<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Malformed public UUIDs must never reach PostgreSQL as a `uuid` comparison.
 *
 * Found on staging against the deployed release, not in review: `/clients/3`
 * produced HTTP 500 with SQLSTATE[22P02] `invalid input syntax for type uuid`,
 * because HasPublicUuid binds 146 models by their `uuid` column and the
 * framework default hands the raw route value to the database.
 *
 * It was never a data exposure — the query stayed firm-scoped — so these
 * tests assert both halves of the fix: malformed input resolves to nothing
 * (not an error), AND the tenant boundary that was already holding is still
 * holding. The guard adds a constraint; it must not have relaxed one.
 *
 * The four negative cases below deliberately all expect the SAME outcome.
 * A malformed key, an unknown key, and another firm's key must be
 * indistinguishable to the caller — if malformed input answered differently
 * (as the 500 did), that difference is an existence oracle.
 */
class MalformedPublicUuidRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    private function firmWithClient(): array
    {
        $firm = Firm::factory()->create();
        $client = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => Client::factory()->create(['firm_id' => $firm->id]),
        );

        return [$firm, $client];
    }

    public static function malformedRouteKeyProvider(): array
    {
        return [
            'sequential integer as string' => ['3'],
            'sequential integer, large' => ['999999'],
            'empty-ish whitespace' => [' '],
            'truncated uuid' => ['01a00790-4319-7387-8cab'],
            'uuid missing hyphens' => ['01a00790431973878cab2e92f24efce4'],
            'uuid with trailing junk' => ['01a00790-4319-7387-8cab-2e92f24efce4x'],
            'non-hex characters' => ['zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz'],
            'sql-ish payload' => ["' or '1'='1"],
            'path traversal-ish' => ['../../etc/passwd'],
        ];
    }

    #[DataProvider('malformedRouteKeyProvider')]
    public function test_malformed_route_key_resolves_to_nothing_without_touching_postgres(string $malformed): void
    {
        [$firm, $client] = $this->firmWithClient();

        (new TenantContextService)->runWithFirmContext($firm, function () use ($malformed, $client) {
            // No QueryException: the guard short-circuits before the uuid
            // comparison is ever built, so this must simply find nothing.
            $resolved = (new Client)->resolveRouteBinding($malformed);

            $this->assertNull(
                $resolved,
                "Malformed route key [{$malformed}] must resolve to null, not raise or match.",
            );

            // The guard must not have broken ordinary resolution.
            $this->assertNotNull((new Client)->resolveRouteBinding($client->uuid));
        });
    }

    public function test_valid_own_firm_uuid_still_resolves(): void
    {
        [$firm, $client] = $this->firmWithClient();

        (new TenantContextService)->runWithFirmContext($firm, function () use ($client) {
            $resolved = (new Client)->resolveRouteBinding($client->uuid);

            $this->assertNotNull($resolved);
            $this->assertSame($client->id, $resolved->id);
        });
    }

    public function test_unknown_but_well_formed_uuid_resolves_to_nothing(): void
    {
        [$firm] = $this->firmWithClient();

        (new TenantContextService)->runWithFirmContext($firm, function () {
            $this->assertNull((new Client)->resolveRouteBinding((string) Str::uuid7()));
        });
    }

    public function test_other_firms_uuid_is_not_resolvable(): void
    {
        [$firmA, $clientA] = $this->firmWithClient();
        [$firmB, $clientB] = $this->firmWithClient();

        (new TenantContextService)->runWithFirmContext($firmA, function () use ($clientB) {
            $this->assertNull(
                (new Client)->resolveRouteBinding($clientB->uuid),
                'Firm A must not resolve a Firm B record by uuid.',
            );
        });

        (new TenantContextService)->runWithFirmContext($firmB, function () use ($clientA) {
            $this->assertNull(
                (new Client)->resolveRouteBinding($clientA->uuid),
                'Firm B must not resolve a Firm A record by uuid.',
            );
        });
    }

    public function test_malformed_and_cross_firm_are_indistinguishable(): void
    {
        [$firmA] = $this->firmWithClient();
        [, $clientB] = $this->firmWithClient();

        (new TenantContextService)->runWithFirmContext($firmA, function () use ($clientB) {
            $malformed = (new Client)->resolveRouteBinding('3');
            $crossFirm = (new Client)->resolveRouteBinding($clientB->uuid);
            $unknown = (new Client)->resolveRouteBinding((string) Str::uuid7());

            $this->assertNull($malformed);
            $this->assertNull($crossFirm);
            $this->assertNull($unknown);
        });
    }

    public function test_guard_is_shared_and_not_client_specific(): void
    {
        // The defect lives in HasPublicUuid, so the fix has to hold for any
        // model using it — proving it on a second, unrelated resource stops
        // this regressing into a Client-only patch.
        $firm = Firm::factory()->create();

        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->create(['firm_id' => $firm->id]);

            $this->assertNull((new Matter)->resolveRouteBinding('3'));
            $this->assertNull((new Matter)->resolveRouteBinding('not-a-uuid'));

            $resolved = (new Matter)->resolveRouteBinding($matter->uuid);
            $this->assertNotNull($resolved);
            $this->assertSame($matter->id, $resolved->id);
        });
    }

    public function test_explicit_non_uuid_binding_field_keeps_stock_behaviour(): void
    {
        // `{model:some_other_field}` is a different contract and must not be
        // swallowed by a uuid-shaped guard.
        [$firm, $client] = $this->firmWithClient();

        (new TenantContextService)->runWithFirmContext($firm, function () use ($client) {
            $resolved = (new Client)->resolveRouteBinding($client->display_name, 'display_name');

            $this->assertNotNull($resolved);
            $this->assertSame($client->id, $resolved->id);
        });
    }
}
