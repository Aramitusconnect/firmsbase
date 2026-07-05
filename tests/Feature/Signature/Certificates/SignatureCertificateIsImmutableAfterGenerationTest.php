<?php

namespace Tests\Feature\Signature\Certificates;

use App\Models\SignatureCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureCertificateIsImmutableAfterGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_existing_certificate_throws(): void
    {
        $certificate = SignatureCertificate::factory()->create();

        $this->expectException(\LogicException::class);
        $certificate->update(['certificate_data_json' => ['tampered' => true]]);
    }

    public function test_deleting_an_existing_certificate_throws(): void
    {
        $certificate = SignatureCertificate::factory()->create();

        $this->expectException(\LogicException::class);
        $certificate->delete();
    }
}
