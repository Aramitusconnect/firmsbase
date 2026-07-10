<?php

namespace Tests\Feature\Security\Login;

use App\Enums\FirmUserStatus;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishFirmTenantContext;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TenantContextMiddlewareTest — internal login/panel access wiring.
 * Proves EstablishFirmTenantContext (resolves which firm the
 * authenticated user is acting as) composed with the existing
 * ApplyTenantDatabaseContext (bridges that resolution into Postgres) —
 * exactly the chain FirmPanelProvider's authMiddleware declares —
 * behaves correctly: the request sees only its own firm's data, and
 * both PHP-memory and Postgres context are cleared afterward, even on
 * exception.
 */
class TenantContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_is_established_for_the_authenticated_users_active_firm_during_the_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userA = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firmA)->forUser($userA)->create(['status' => FirmUserStatus::Active]);

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $request = Request::create('/firm', 'GET');
        $request->setUserResolver(fn () => $userA);

        $visibleClientIds = $this->runChain($request, function () use ($firmA) {
            $this->assertDatabaseTenantContextIs($firmA);

            return Client::query()->pluck('id')->all();
        });

        $this->assertContains($clientA->id, $visibleClientIds);
        $this->assertNotContains($clientB->id, $visibleClientIds);
    }

    public function test_tenant_context_clears_after_the_request_completes(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $request = Request::create('/firm', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->runChain($request, fn () => 'ok');

        $this->assertFalse((new TenantContextService())->hasFirmContext(), 'PHP-memory firm context must clear after the request.');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_even_if_the_next_handler_throws(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $request = Request::create('/firm', 'GET');
        $request->setUserResolver(fn () => $user);

        try {
            $this->runChain($request, function () {
                throw new \RuntimeException('simulated failure inside the firm panel request');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertFalse((new TenantContextService())->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_user_with_no_active_firm_membership_gets_no_tenant_context_established(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $request = Request::create('/firm', 'GET');
        $request->setUserResolver(fn () => $user);

        $ran = false;

        $this->runChain($request, function () use (&$ran) {
            $ran = true;
            $this->assertFalse((new TenantContextService())->hasFirmContext());

            return 'ok';
        });

        $this->assertTrue($ran, 'The next handler must still run even with no firm membership.');
    }

    private function runChain(Request $request, \Closure $destination): mixed
    {
        $establishFirm = new EstablishFirmTenantContext(new TenantContextService());
        $applyDb = new ApplyTenantDatabaseContext(new TenantContextService());

        return $establishFirm->handle($request, fn ($req) => $applyDb->handle($req, $destination));
    }
}
