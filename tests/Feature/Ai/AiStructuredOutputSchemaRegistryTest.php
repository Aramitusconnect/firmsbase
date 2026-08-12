<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\AiStructuredOutputSchemaRegistry;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 5 —
 * AiStructuredOutputSchemaRegistry: closed-vocabulary shape
 * enforcement, mirroring AutomationFieldAllowlistRegistryTest's own
 * rigor (every field required, no unlisted field tolerated, enum
 * constraints enforced).
 */
class AiStructuredOutputSchemaRegistryTest extends TestCase
{
    public function test_has_recognizes_a_registered_key(): void
    {
        $this->assertTrue(AiStructuredOutputSchemaRegistry::has('practice_area_classification'));
        $this->assertFalse(AiStructuredOutputSchemaRegistry::has('nonexistent_schema'));
    }

    public function test_schema_for_returns_the_field_type_map(): void
    {
        $schema = AiStructuredOutputSchemaRegistry::schemaFor('practice_area_classification');

        $this->assertSame(['practice_area_code' => 'string', 'confidence' => 'string'], $schema);
    }

    public function test_schema_for_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull(AiStructuredOutputSchemaRegistry::schemaFor('nonexistent_schema'));
    }

    public function test_validate_passes_for_a_conformant_payload(): void
    {
        $errors = AiStructuredOutputSchemaRegistry::validate('practice_area_classification', [
            'practice_area_code' => 'family_law',
            'confidence' => 'medium',
        ]);

        $this->assertEmpty($errors);
    }

    public function test_validate_rejects_an_unknown_schema_key(): void
    {
        $errors = AiStructuredOutputSchemaRegistry::validate('nonexistent_schema', ['x' => 'y']);

        $this->assertArrayHasKey('_schema', $errors);
    }

    public function test_validate_rejects_a_missing_required_field(): void
    {
        $errors = AiStructuredOutputSchemaRegistry::validate('practice_area_classification', [
            'practice_area_code' => 'family_law',
        ]);

        $this->assertArrayHasKey('confidence', $errors);
    }

    public function test_validate_rejects_a_wrongly_typed_field(): void
    {
        $errors = AiStructuredOutputSchemaRegistry::validate('practice_area_classification', [
            'practice_area_code' => 123,
            'confidence' => 'medium',
        ]);

        $this->assertArrayHasKey('practice_area_code', $errors);
    }

    public function test_validate_rejects_an_unlisted_extra_field(): void
    {
        $errors = AiStructuredOutputSchemaRegistry::validate('practice_area_classification', [
            'practice_area_code' => 'family_law',
            'confidence' => 'medium',
            'injected_field' => 'malicious',
        ]);

        $this->assertArrayHasKey('injected_field', $errors);
    }

    public function test_validate_rejects_a_confidence_value_outside_the_enum(): void
    {
        $errors = AiStructuredOutputSchemaRegistry::validate('practice_area_classification', [
            'practice_area_code' => 'family_law',
            'confidence' => 'extremely_certain',
        ]);

        $this->assertArrayHasKey('confidence', $errors);
    }

    public function test_validate_accepts_every_enum_value_for_confidence(): void
    {
        foreach (['low', 'medium', 'high'] as $confidence) {
            $errors = AiStructuredOutputSchemaRegistry::validate('practice_area_classification', [
                'practice_area_code' => 'family_law',
                'confidence' => $confidence,
            ]);

            $this->assertEmpty($errors, "confidence={$confidence} should be valid.");
        }
    }
}
