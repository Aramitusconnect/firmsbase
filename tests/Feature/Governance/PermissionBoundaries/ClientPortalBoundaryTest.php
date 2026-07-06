<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\GovernanceMappingStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\PermissionMatrixMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ClientPortalBoundaryTest — proves Client is structurally isolated
 * from FirmUser/FirmUserRole, matching FirmUserRole's own documented
 * design rule that mixing internal staff and external clients into
 * one role enum would blur a boundary that must stay hard.
 */
class ClientPortalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_is_not_a_firm_user(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->create(['firm_id' => $firm->id]);

        $this->assertNotInstanceOf(FirmUser::class, $client);
    }

    public function test_client_does_not_have_a_firm_user_role_cast(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->create(['firm_id' => $firm->id]);

        $this->assertArrayNotHasKey('role', $client->getCasts());
        $this->assertFalse(in_array('role', $client->getFillable(), true));
    }

    public function test_firm_user_queries_do_not_return_clients(): void
    {
        $firm = Firm::factory()->create();
        Client::factory()->create(['firm_id' => $firm->id]);
        $firmUser = FirmUser::factory()->forFirm($firm)->create();

        $ids = FirmUser::query()->where('firm_id', $firm->id)->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertSame($firmUser->id, $ids->first());
    }

    public function test_client_key_in_permission_matrix_mapping_service_is_implemented_structurally(): void
    {
        $result = (new PermissionMatrixMappingService())->clientBoundary();

        $this->assertSame(GovernanceMappingStatus::Implemented, $result->status);
        $this->assertSame(Client::class, $result->owning_class);
    }
}
