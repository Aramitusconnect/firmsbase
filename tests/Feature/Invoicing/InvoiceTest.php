<?php

namespace Tests\Feature\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
    }

    public function test_forFirm_ties_invoice_and_client_to_the_same_firm(): void
    {
        $firm = \App\Models\Firm::factory()->create();

        $invoice = Invoice::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $invoice->firm_id);
        $this->assertSame($firm->id, $invoice->client->firm_id);
    }
}
