<?php

declare(strict_types=1);

namespace App\Services;

/**
 * AiStructuredOutputSchemaRegistry — Mission 3 (MyAttorney Conversion
 * + AI Intake), checkpoint 5. The single source of truth for what
 * shape a structured AI response must have for a given
 * AiPromptRequest::responseSchemaKey — mirrors
 * AutomationFieldAllowlistRegistry's own convention exactly:
 * deliberately hand-authored per key (never reflection over a model's
 * own columns/fillable), closed vocabulary (an unlisted key in the
 * response, or a missing/mistyped required key, is always an error,
 * never silently ignored or coerced), and consulted from a single
 * place so a caller and a validator can never drift apart.
 *
 * Nothing in this codebase auto-trusts a structured AI response
 * merely because AiProviderResponse::structuredOutput is non-null —
 * validate() must be called first, and its result checked, before
 * that data is used for anything (e.g. persisted into
 * MarketplaceIntake::structured_data or read to select a practice
 * area). An AI classification is a PROPOSAL, never authoritative on
 * its own — this class enforces shape only, never correctness of the
 * classification itself.
 */
final class AiStructuredOutputSchemaRegistry
{
    /**
     * Each schema entry maps a field name to a primitive PHP type
     * ('string', 'int', 'float', 'bool') every response for that key
     * must satisfy. A field entry may additionally appear in
     * ENUM_CONSTRAINTS below to further restrict it to a fixed set of
     * values.
     */
    private const SCHEMAS = [
        'practice_area_classification' => [
            'practice_area_code' => 'string',
            'confidence' => 'string',
        ],
        // Mission 3, checkpoint 6 -- a single conversational-intake
        // field-extraction turn. question_code must be an existing
        // IntakeTemplateQuestion.question_code on the intake's own
        // attached template; extracted_value is always a raw string
        // here (IntakeTemplateService::validateResponses() is the
        // ONLY place that further checks it against the target
        // question's real type/options) -- this schema enforces
        // shape only, never field-specific validity.
        'intake_field_extraction' => [
            'question_code' => 'string',
            'extracted_value' => 'string',
        ],
    ];

    /**
     * Deliberately a coarse, human-readable bucket (low/medium/high)
     * rather than a raw float — a classification confidence score is
     * never precise enough to justify false numeric precision, and a
     * bucket is what any human reviewer actually needs to decide
     * whether to trust it. See this class's own docblock: the
     * classification itself is always a proposal, never authoritative.
     */
    private const ENUM_CONSTRAINTS = [
        'practice_area_classification' => [
            'confidence' => ['low', 'medium', 'high'],
        ],
    ];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::SCHEMAS);
    }

    /**
     * @return array<string, string>|null
     */
    public static function schemaFor(string $key): ?array
    {
        return self::SCHEMAS[$key] ?? null;
    }

    /**
     * Validates $data against the schema for $key. Returns a map of
     * field => error message (empty array = valid). Every schema
     * field must be present and correctly typed; every key in $data
     * must be a known schema field — an unlisted key is always
     * rejected, never silently dropped or passed through.
     *
     * @return array<string, string>
     */
    public static function validate(string $key, array $data): array
    {
        $schema = self::schemaFor($key);

        if ($schema === null) {
            return ['_schema' => "Unknown structured output schema: {$key}"];
        }

        $errors = [];

        foreach (array_keys($data) as $field) {
            if (! array_key_exists($field, $schema)) {
                $errors[$field] = "Unexpected field for schema {$key}: {$field}";
            }
        }

        foreach ($schema as $field => $type) {
            if (! array_key_exists($field, $data)) {
                $errors[$field] = "Missing required field: {$field}";

                continue;
            }

            $value = $data[$field];

            if (! self::matchesType($value, $type)) {
                $errors[$field] = "Field {$field} must be of type {$type}.";

                continue;
            }

            $allowedValues = self::ENUM_CONSTRAINTS[$key][$field] ?? null;

            if ($allowedValues !== null && ! in_array($value, $allowedValues, true)) {
                $errors[$field] = "Field {$field} must be one of: ".implode(', ', $allowedValues);
            }
        }

        return $errors;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            default => false,
        };
    }
}
