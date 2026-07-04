<?php

namespace Tests\Feature\Invoicing;

use App\Enums\InvoiceLineType;
use App\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $line = InvoiceLine::factory()->create();

        $this->assertDatabaseHas('invoice_lines', ['id' => $line->id]);
        $this->assertSame(InvoiceLineType::ManualCharge, $line->line_type);
    }

    public function test_no_own_firm_id_column_exists(): void
    {
        $line = InvoiceLine::factory()->create();

        $this->assertArrayNotHasKey('firm_id', $line->getAttributes());
    }
}
