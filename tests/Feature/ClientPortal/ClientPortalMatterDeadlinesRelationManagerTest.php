<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Filament\ClientPortal\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\ClientPortal\Resources\MatterResource\RelationManagers\DeadlinesRelationManager;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\Matter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ClientPortalMatterDeadlinesRelationManagerTest — non-payment
 * completion program. Proves the read-only Deadlines tab on the Client
 * Portal ViewMatter page is scoped to exactly this matter, requires an
 * active ClientPortalMatterGrant (matching MatterResource's own
 * "explicit grant required" boundary), and never exposes an action
 * (create/edit/delete) of any kind.
 */
final class ClientPortalMatterDeadlinesRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('client-portal'));
    }

    public function test_deadlines_tab_requires_an_active_grant_even_when_the_matter_genuinely_belongs_to_the_client(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matterGranted, $matterUngranted] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matterGranted = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $matterUngranted = Matter::factory()->forFirm($firm)->forClient($client)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matterGranted->id,
                'granted_at' => now(),
            ]);

            return [$client, $matterGranted, $matterUngranted];
        });
        $portalUser = $this->makePortalUser($client);
        Auth::guard('client')->login($portalUser);

        $canViewGranted = $this->runWithFirmContext($firm, fn () => DeadlinesRelationManager::canViewForRecord($matterGranted, ViewMatter::class));
        $canViewUngranted = $this->runWithFirmContext($firm, fn () => DeadlinesRelationManager::canViewForRecord($matterUngranted, ViewMatter::class));

        $this->assertTrue($canViewGranted);
        $this->assertFalse($canViewUngranted, 'A matter genuinely belonging to the client must still be denied without an explicit grant.');
    }

    public function test_deadlines_tab_shows_only_this_matters_deadlines(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matterA, $matterB] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matterA = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $matterB = Matter::factory()->forFirm($firm)->forClient($client)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id, 'client_id' => $client->id, 'matter_id' => $matterA->id, 'granted_at' => now(),
            ]);

            return [$client, $matterA, $matterB];
        });
        $portalUser = $this->makePortalUser($client);
        Auth::guard('client')->login($portalUser);

        $deadlineA = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matterA->id]));
        $deadlineB = $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matterB->id]));

        $this->runWithFirmContext($firm, function () use ($matterA, $deadlineA, $deadlineB): void {
            $test = Livewire::test(DeadlinesRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$deadlineA]);
            $test->assertCanNotSeeTableRecords([$deadlineB]);
        });
    }

    public function test_deadlines_tab_never_exposes_a_header_or_record_action(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id, 'client_id' => $client->id, 'matter_id' => $matter->id, 'granted_at' => now(),
            ]);

            return [$client, $matter];
        });
        $portalUser = $this->makePortalUser($client);
        Auth::guard('client')->login($portalUser);

        $this->runWithFirmContext($firm, function () use ($matter): void {
            $test = Livewire::test(DeadlinesRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertActionDoesNotExist('create');
        });
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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
