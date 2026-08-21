<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Filament\ClientPortal\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClientPortalInvoiceResourceTest — Mission 4 (Client Portal
 * Activation), finding 4.6. Proves InvoiceResource's client_id + grant
 * gating: an invoice for a matter the client has no active
 * ClientPortalMatterGrant for must never be visible, even though its
 * client_id matches the authenticated client exactly — the same
 * "explicit grant required" principle proven for Matter itself. An
 * invoice with no matter_id at all is visible on client_id scoping
 * alone. Read-only — no Stripe/Finix/payment-provider code touched.
 */
class ClientPortalInvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_matter_linked_invoice_is_visible_when_the_client_has_an_active_grant_for_that_matter(): void
    {
        $firm = Firm::factory()->create();
        [$client, $invoice] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            $invoice = Invoice::factory()->forMatter($matter)->create();

            return [$client, $invoice];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => InvoiceResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => InvoiceResource::isVisibleToPortalUser($invoice->fresh(), $portalUser));

        $this->assertContains($invoice->id, $ids);
        $this->assertTrue($visible);
    }

    public function test_a_matter_linked_invoice_is_never_visible_without_an_active_grant_for_that_matter_even_though_client_id_matches(): void
    {
        $firm = Firm::factory()->create();
        [$client, $invoice] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            // Genuinely this client's matter (client_id matches) — but
            // no ClientPortalMatterGrant exists for it.
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $invoice = Invoice::factory()->forMatter($matter)->create();

            return [$client, $invoice];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => InvoiceResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => InvoiceResource::isVisibleToPortalUser($invoice->fresh(), $portalUser));

        $this->assertNotContains(
            $invoice->id,
            $ids,
            'An invoice for a matter the client has no grant for must never be visible, even though invoice.client_id matches.'
        );
        $this->assertFalse($visible);
    }

    public function test_an_invoice_with_no_matter_id_is_visible_on_client_id_scoping_alone(): void
    {
        $firm = Firm::factory()->create();
        [$client, $invoice] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $invoice = Invoice::factory()->forClient($client)->create(); // matter_id left null
            $this->assertNull($invoice->matter_id);

            return [$client, $invoice];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => InvoiceResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => InvoiceResource::isVisibleToPortalUser($invoice->fresh(), $portalUser));

        $this->assertContains($invoice->id, $ids);
        $this->assertTrue($visible);
    }

    public function test_a_different_clients_invoice_is_never_visible_regardless_of_matter_grants(): void
    {
        $firm = Firm::factory()->create();
        [$clientA, $invoiceB] = $this->runWithFirmContext($firm, function () use ($firm) {
            $clientA = Client::factory()->forFirm($firm)->create();
            $clientB = Client::factory()->forFirm($firm)->create();
            $invoiceB = Invoice::factory()->forClient($clientB)->create();

            return [$clientA, $invoiceB];
        });
        $portalUserA = $this->makePortalUser($clientA);

        Auth::guard('client')->login($portalUserA);

        $ids = $this->runWithFirmContext($firm, fn () => InvoiceResource::getEloquentQuery()->pluck('id')->all());
        $visible = $this->runWithFirmContext($firm, fn () => InvoiceResource::isVisibleToPortalUser($invoiceB->fresh(), $portalUserA));

        $this->assertNotContains($invoiceB->id, $ids);
        $this->assertFalse($visible);
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
