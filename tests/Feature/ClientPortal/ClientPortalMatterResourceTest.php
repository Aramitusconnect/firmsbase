<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Filament\ClientPortal\Resources\MatterResource;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClientPortalMatterResourceTest — Mission 4 (Client Portal Activation),
 * finding 4.3. Proves MatterResource::getEloquentQuery() (the list-level
 * UX filter) is scoped EXCLUSIVELY through
 * ClientPortalMatterAccessPolicyService::grantedMatterIds() — a matter
 * that genuinely belongs to the authenticated client (Matter.client_id
 * matches) but has no explicit ClientPortalMatterGrant must never
 * appear — and that the resource/its pages never reference any of the
 * internal-only Matter fields/relations this mission's field allowlist
 * forbids.
 */
class ClientPortalMatterResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_with_a_grant_sees_the_matter_in_the_resource_query(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            return [$client, $matter];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertContains($matter->id, $ids);
    }

    public function test_a_client_without_a_grant_does_not_see_the_matter_in_the_resource_query_even_though_it_is_genuinely_theirs(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            // Genuinely belongs to this client via Matter.client_id —
            // but NO ClientPortalMatterGrant row exists.
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            return [$client, $matter];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains(
            $matter->id,
            $ids,
            'A matter must never appear in the Client Portal list via an inferred Matter.client_id match alone — an explicit grant is required.'
        );
    }

    public function test_a_client_never_sees_another_clients_matter_even_when_it_has_a_grant_for_a_different_client(): void
    {
        $firm = Firm::factory()->create();
        [$clientA, $clientB, $matterB] = $this->runWithFirmContext($firm, function () use ($firm) {
            $clientA = Client::factory()->forFirm($firm)->create();
            $clientB = Client::factory()->forFirm($firm)->create();
            $matterB = Matter::factory()->forFirm($firm)->forClient($clientB)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $clientB->id,
                'matter_id' => $matterB->id,
                'granted_at' => now(),
            ]);

            return [$clientA, $clientB, $matterB];
        });
        $portalUserA = $this->makePortalUser($clientA);

        Auth::guard('client')->login($portalUserA);

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($matterB->id, $ids, 'Client A must never see a matter granted only to Client B.');
    }

    public function test_matter_resource_and_its_pages_never_reference_internal_only_fields_or_relations(): void
    {
        $forbidden = [
            'conflictCheckRuns',
            'matterAssignments',
            'intakeSubmissions',
            'readinessScore',
            'timeEntries',
            'expenses',
            'matterBudgets',
            'leverageRecommendations',
        ];

        foreach ([
            app_path('Filament/ClientPortal/Resources/MatterResource.php'),
            app_path('Filament/ClientPortal/Resources/MatterResource/Pages/ListMatters.php'),
            app_path('Filament/ClientPortal/Resources/MatterResource/Pages/ViewMatter.php'),
        ] as $file) {
            $this->assertFileExists($file);
            $code = $this->stripComments((string) file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$file} must never reference {$needle}.");
            }
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Strips comments/docblocks via PHP's own tokenizer first — mirrors
     * FinancialEvidenceClientPortalConsentFlowTest's identical technique
     * — so a docblock discussing a forbidden field name in prose (to
     * document the constraint for a human reader) never false-positives
     * a naive string-contains scan over the raw file.
     */
    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    private function makePortalUser(Client $client): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => 'client-'.Str::random(8).'@example.test',
            'password' => 'irrelevant-hashed-value',
            'is_active' => true,
        ]));
    }
}
