<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * NoRawModelInOutboxPayloadTest — Checkpoint 6 static-assertion test
 * (reviews/checkpoint-06/frozen-design-post-review.md §11;
 * agent-6h-test-plan-and-review.md §6 item 16a). Mirrors
 * tests/Unit/Integrations/NoRealNetworkCallTest.php's convention
 * exactly: a pure source-file scan (no framework boot, no database) of
 * every file under app/Integrations/Services/ and app/Integrations/Data/
 * for a `->toArray()` or `json_encode(` call whose argument is
 * Model-typed — the structural claim behind SanitizedPayloadReference
 * being the ONLY type any outbox/sync write path accepts.
 *
 * Deliberately does NOT flag the frozen, intentional boundary type:
 * IntegrationOutboxPayloadBuilderService::build()'s second parameter is
 * declared as the generic `object $subject` (never `Model` directly),
 * per that class's own docblock — this is the one narrow, reviewed
 * translation point between a real domain model and the DTO, and is
 * not itself a violation (the violation would be calling
 * `$subject->toArray()`/`json_encode($subject)` INSIDE that boundary,
 * which this scan does catch, since it flags any `object`-typed
 * receiver actually reached via one of the two forbidden calls too —
 * see variableIsModelOrObjectTyped()).
 */
final class NoRawModelInOutboxPayloadTest extends TestCase
{
    private const SCAN_DIRECTORIES = ['Services', 'Data'];

    public function test_scan_covers_a_non_empty_file_set(): void
    {
        $files = self::allSourceFiles();

        $this->assertNotEmpty($files);
        $this->assertGreaterThanOrEqual(8, count($files), 'Expected at least the 7 Checkpoint 6 services + payload builder + DTO to be scanned.');
    }

    public function test_no_file_calls_to_array_on_a_model_or_object_typed_variable(): void
    {
        foreach (self::allSourceFiles() as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $scannable = self::stripComments($source);

            foreach (self::extractToArrayReceivers($scannable) as $variable) {
                $this->assertFalse(
                    self::variableIsModelOrObjectTyped($scannable, $variable),
                    "{$file} calls {$variable}->toArray() where {$variable} appears to be typed as an Eloquent Model, an App\\Models\\* class, or the generic 'object' boundary type — payload columns must never receive a raw model's toArray() output."
                );
            }
        }
    }

    public function test_no_file_calls_json_encode_directly_on_a_model_or_object_typed_variable(): void
    {
        foreach (self::allSourceFiles() as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $scannable = self::stripComments($source);

            foreach (self::extractJsonEncodeReceivers($scannable) as $variable) {
                $this->assertFalse(
                    self::variableIsModelOrObjectTyped($scannable, $variable),
                    "{$file} calls json_encode({$variable}, ...) where {$variable} appears to be typed as an Eloquent Model, an App\\Models\\* class, or the generic 'object' boundary type."
                );
            }
        }
    }

    // ------------------------------------------------------------
    // Self-verification: prove the detection logic itself is sound —
    // it must catch a real violation and must not false-positive on
    // the codebase's actual, legitimate calls (SanitizedPayloadReference
    // ::toArray()/::hash() operating on $this; json_encode() of a plain
    // array built from an already-sanitized reference).
    // ------------------------------------------------------------

    public function test_detection_catches_to_array_called_on_a_model_typed_parameter(): void
    {
        $maliciousSource = <<<'PHP'
            <?php
            final class LeakyBuilder
            {
                public function build(\Illuminate\Database\Eloquent\Model $subject): array
                {
                    return $subject->toArray();
                }
            }
            PHP;

        $receivers = self::extractToArrayReceivers(self::stripComments($maliciousSource));
        $this->assertContains('$subject', $receivers);
        $this->assertTrue(self::variableIsModelOrObjectTyped(self::stripComments($maliciousSource), '$subject'));
    }

    public function test_detection_catches_to_array_called_on_an_app_models_typed_parameter(): void
    {
        $maliciousSource = <<<'PHP'
            <?php
            final class LeakyBuilder
            {
                public function build(App\Models\Contact $contact): array
                {
                    return $contact->toArray();
                }
            }
            PHP;

        $scannable = self::stripComments($maliciousSource);
        $receivers = self::extractToArrayReceivers($scannable);
        $this->assertContains('$contact', $receivers);
        $this->assertTrue(self::variableIsModelOrObjectTyped($scannable, '$contact'));
    }

    public function test_detection_catches_to_array_called_on_the_frozen_generic_object_boundary_type(): void
    {
        $maliciousSource = <<<'PHP'
            <?php
            final class LeakyBuilder
            {
                public function build(object $subject): array
                {
                    return $subject->toArray();
                }
            }
            PHP;

        $scannable = self::stripComments($maliciousSource);
        $receivers = self::extractToArrayReceivers($scannable);
        $this->assertContains('$subject', $receivers);
        $this->assertTrue(
            self::variableIsModelOrObjectTyped($scannable, '$subject'),
            'A raw ->toArray() call on the object-typed $subject parameter itself would be the exact leak this test exists to prevent.'
        );
    }

    public function test_detection_does_not_flag_to_array_or_hash_called_on_this(): void
    {
        $legitimateSource = <<<'PHP'
            <?php
            final class SanitizedPayloadReference
            {
                public function toArray(): array
                {
                    return ['resource_type' => $this->resourceType->value];
                }

                public function hash(): string
                {
                    $canonical = $this->toArray();

                    return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
                }
            }
            PHP;

        $scannable = self::stripComments($legitimateSource);
        $receivers = self::extractToArrayReceivers($scannable);

        // $this->toArray() IS captured by the scan (receiver = '$this'),
        // but $this is never type-hinted as Model/App\Models\*/object
        // anywhere (it is the DTO's own implicit current-object
        // reference) — the detection logic must correctly clear it.
        $this->assertSame(['$this'], $receivers);
        $this->assertFalse(self::variableIsModelOrObjectTyped($scannable, '$this'));

        // json_encode($canonical, ...) operates on a plain local array
        // variable, never a Model-typed one.
        foreach (self::extractJsonEncodeReceivers($scannable) as $variable) {
            $this->assertFalse(self::variableIsModelOrObjectTyped($scannable, $variable));
        }
    }

    // ------------------------------------------------------------
    // Scanning primitives
    // ------------------------------------------------------------

    /**
     * @return string[]
     */
    private static function allSourceFiles(): array
    {
        $files = [];

        foreach (self::SCAN_DIRECTORIES as $dir) {
            $root = dirname(__DIR__, 3)."/app/Integrations/{$dir}";

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function stripComments(string $source): string
    {
        $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $source);
        $lines = explode("\n", $withoutBlockComments ?? $source);

        $codeLines = array_filter(
            $lines,
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '//')
        );

        return implode("\n", $codeLines);
    }

    /**
     * @return string[] variable names (with leading $), e.g. ['$subject']
     */
    private static function extractToArrayReceivers(string $scannable): array
    {
        // Matches both the plain arrow (->) and the nullsafe arrow
        // (?->), e.g. $payload?->toArray().
        if (! preg_match_all('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*\??->\s*toArray\s*\(/', $scannable, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return string[] variable names (with leading $)
     */
    private static function extractJsonEncodeReceivers(string $scannable): array
    {
        if (! preg_match_all('/json_encode\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*)/', $scannable, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * True when $variable, within $scannable, is declared via a
     * parameter type-hint of Model, any App\Models\* class, or the
     * generic `object` boundary type — or is assigned via `new` from
     * one of those same class shapes.
     */
    private static function variableIsModelOrObjectTyped(string $scannable, string $variable): bool
    {
        $escaped = preg_quote($variable, '/');

        // Matches the type hint at ANY parameter position — immediately
        // after '(' (first parameter) or ',' (any later parameter),
        // optionally nullable ('?Type $var').
        $typeHintPattern = '/[(,]\s*\??\s*(?:\\\\?(?:Illuminate\\\\Database\\\\Eloquent\\\\)?Model|\\\\?App\\\\Models\\\\[A-Za-z_]+|object)\s+'.$escaped.'\b/';
        if (preg_match($typeHintPattern, $scannable)) {
            return true;
        }

        $instantiationPattern = '/'.$escaped.'\s*=\s*new\s+(?:\\\\?(?:Illuminate\\\\Database\\\\Eloquent\\\\)?Model\b|\\\\?App\\\\Models\\\\[A-Za-z_]+)/';
        if (preg_match($instantiationPattern, $scannable)) {
            return true;
        }

        return false;
    }
}
