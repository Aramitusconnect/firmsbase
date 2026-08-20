<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

use App\Services\AiStructuredOutputSchemaRegistry;

/**
 * Translates the provider-independent schema registry into the JSON Schema the
 * Responses API expects.
 *
 * The registry stays the single source of truth for what a turn may return;
 * this class only expresses it in OpenAI's dialect. `strict: true` requires
 * every property to be listed in `required` and `additionalProperties: false`,
 * which is also exactly the behaviour we want — a model cannot invent an extra
 * intake field.
 *
 * @internal to the OpenAI integration.
 */
final class OpenAiStructuredSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function forRegistryKey(string $key): array
    {
        $fields = AiStructuredOutputSchemaRegistry::schemaFor($key) ?? [];
        $enums = AiStructuredOutputSchemaRegistry::enumConstraintsFor($key) ?? [];

        $properties = [];

        foreach ($fields as $field => $type) {
            $property = ['type' => self::jsonType($type)];

            if (isset($enums[$field]) && is_array($enums[$field])) {
                $property['enum'] = array_values($enums[$field]);
            }

            $properties[$field] = $property;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            // strict mode requires every property to be required.
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    private static function jsonType(string $registryType): string
    {
        return match ($registryType) {
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }
}
