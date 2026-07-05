<?php

namespace Tests\Feature;

use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the approved Phase 11 uuid decision: 3 workflow/evidence
 * models carry a public uuid (SignatureRequest, SignatureRequestRecipient,
 * SignatureCertificate); signature_events, document_hashes, and
 * pdf_view_events (pure append-only ledger rows) do not.
 */
class Phase11PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uuidModelProvider')]
    public function test_model_has_a_public_uuid(string $modelClass): void
    {
        $instance = $modelClass::factory()->create();

        $this->assertNotNull($instance->uuid);
    }

    public static function uuidModelProvider(): array
    {
        return [
            [SignatureRequest::class],
            [SignatureRequestRecipient::class],
            [SignatureCertificate::class],
        ];
    }

    #[DataProvider('noUuidTableProvider')]
    public function test_table_has_no_uuid_column(string $table): void
    {
        $columns = Schema::getColumnListing($table);

        $this->assertNotContains('uuid', $columns);
    }

    public static function noUuidTableProvider(): array
    {
        return [
            ['signature_events'],
            ['document_hashes'],
            ['pdf_view_events'],
        ];
    }
}
