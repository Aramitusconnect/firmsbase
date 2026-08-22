<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Enums\PaymentClassification;
use App\Integrations\Data\SanitizedPayloadReference;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Services\IntegrationOutboxPayloadBuilderService;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationOutboxPayloadBuilderServiceTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §11;
 * agent-6h-test-plan-and-review.md §6 item 16). Extends Tests\TestCase
 * with RefreshDatabase (matching
 * tests/Unit/Integrations/Support/OutboundProviderHttpClientTest.php's
 * precedent for a DB-backed test living under tests/Unit/Integrations/)
 * because build()'s runtime behavior can only be meaningfully proven
 * against real Eloquent model instances.
 */
class IntegrationOutboxPayloadBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxPayloadBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationOutboxPayloadBuilderService;
    }

    // ------------------------------------------------------------
    // Reflection: build()'s only accepted parameter types are
    // ResourceType + a plain object/DTO, never Model.
    // ------------------------------------------------------------

    public function test_build_signature_never_accepts_a_model_directly(): void
    {
        $method = new ReflectionMethod(IntegrationOutboxPayloadBuilderService::class, 'build');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);

        $firstType = $parameters[0]->getType();
        $this->assertNotNull($firstType);
        $this->assertSame(ResourceType::class, (string) $firstType);

        $secondType = $parameters[1]->getType();
        $this->assertNotNull($secondType);
        $this->assertSame('object', (string) $secondType, 'The second parameter must be the generic "object" type, never Model or any Model subtype.');
        $this->assertNotSame(Model::class, (string) $secondType);

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame(SanitizedPayloadReference::class, (string) $returnType);
    }

    public function test_no_private_builder_method_declares_a_model_typed_parameter(): void
    {
        $reflection = new \ReflectionClass(IntegrationOutboxPayloadBuilderService::class);

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type === null) {
                    continue;
                }

                $this->assertNotSame(
                    Model::class,
                    (string) $type,
                    "{$method->getName()}() must never declare a parameter typed as the raw Eloquent Model class."
                );
            }
        }
    }

    // ------------------------------------------------------------
    // Runtime: a credential/document-content-shaped field never appears
    // in the built reference.
    // ------------------------------------------------------------

    public function test_document_storage_path_and_file_hash_never_appear_in_the_built_payload(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create([
            'firm_id' => $firm->id,
            'storage_path' => 'super-secret-tenant-bucket/matter-9182/privileged-memo.pdf',
            'file_hash' => 'sha256-of-a-real-privileged-document-do-not-leak',
        ]);

        $reference = $this->service->build(ResourceType::Document, $document);

        $this->assertArrayNotHasKey('storage_path', $reference->fields);
        $this->assertArrayNotHasKey('storage_disk', $reference->fields);
        $this->assertArrayNotHasKey('file_hash', $reference->fields);
        $this->assertArrayNotHasKey('original_filename', $reference->fields);

        $encoded = json_encode($reference->toArray());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('super-secret-tenant-bucket', $encoded);
        $this->assertStringNotContainsString('sha256-of-a-real-privileged-document-do-not-leak', $encoded);
    }

    public function test_payment_builder_rejects_a_trust_iolta_payment_before_it_can_ever_be_built(): void
    {
        $firm = Firm::factory()->create();
        $payment = Payment::factory()->forFirm($firm)->create([
            'payment_classification' => PaymentClassification::TrustIoltaPayment,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/trust_iolta_payment must never be recorded/');

        $this->service->build(ResourceType::Payment, $payment);
    }

    public function test_a_non_trust_payment_builds_only_the_allowlisted_fields(): void
    {
        $firm = Firm::factory()->create();
        $payment = Payment::factory()->forFirm($firm)->create([
            'payment_classification' => PaymentClassification::OperatingPayment,
        ]);

        $reference = $this->service->build(ResourceType::Payment, $payment);

        $this->assertSame(
            ['amount_cents', 'classification', 'status'],
            array_keys($reference->fields)
        );
    }

    // ------------------------------------------------------------
    // payload_hash is computed only over the sanitized reference.
    // ------------------------------------------------------------

    public function test_hash_does_not_change_when_an_out_of_allowlist_source_field_changes(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create([
            'firm_id' => $firm->id,
            'storage_path' => 'documents/original.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $referenceBefore = $this->service->build(ResourceType::Document, $document);
        $hashBefore = $referenceBefore->hash();

        // Mutate an out-of-allowlist field (storage_path is never read by
        // buildDocument()) and rebuild.
        $document->storage_path = 'documents/moved-to-a-totally-different-bucket.pdf';
        $document->file_hash = 'a-completely-different-hash-value';

        $referenceAfter = $this->service->build(ResourceType::Document, $document);
        $hashAfter = $referenceAfter->hash();

        $this->assertSame($hashBefore, $hashAfter, 'Changing an out-of-allowlist source field must never change the resulting payload_hash.');
    }

    public function test_hash_changes_when_an_allowlisted_field_changes(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create([
            'firm_id' => $firm->id,
            'mime_type' => 'application/pdf',
        ]);

        $hashBefore = $this->service->build(ResourceType::Document, $document)->hash();

        $document->mime_type = 'image/png';

        $hashAfter = $this->service->build(ResourceType::Document, $document)->hash();

        $this->assertNotSame($hashBefore, $hashAfter, 'Changing an allowlisted field must change the resulting payload_hash.');
    }

    public function test_hash_is_computed_only_from_to_array_never_from_a_wider_structure(): void
    {
        $firm = Firm::factory()->create();
        $contact = Contact::factory()->forFirm($firm)->create(['name' => 'Jane Doe', 'email' => 'jane@example.test']);

        $reference = $this->service->build(ResourceType::Contact, $contact);

        $canonical = $reference->toArray();
        ksort($canonical['fields']);
        $expectedHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        $this->assertSame($expectedHash, $reference->hash());
    }
}
