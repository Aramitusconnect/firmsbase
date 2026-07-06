<?php

namespace Tests\Feature\Governance\DataModelContract;

use Tests\TestCase;

/**
 * MigrationExpandContractDisciplineTest — scans every migration file
 * for destructive schema operations, but ONLY inside up() methods.
 * down() methods are rollback behavior (every create_*_table migration
 * legitimately calls Schema::dropIfExists() in its own down()) and are
 * deliberately excluded from this check, per the master plan's
 * expand/contract migration discipline rule.
 */
class MigrationExpandContractDisciplineTest extends TestCase
{
    private const DESTRUCTIVE_PATTERNS = [
        '/->dropColumn\s*\(/',
        '/->dropTable\s*\(/',
        '/Schema::drop(?!IfExists)/', // Schema::drop( but not Schema::dropIfExists(
        '/->renameColumn\s*\(/',
        '/DROP\s+TABLE/i',
        '/TRUNCATE/i',
        '/DELETE\s+FROM/i',
    ];

    public function test_no_migration_performs_a_destructive_operation_inside_up(): void
    {
        $violations = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $upBody = $this->extractMethodBody(file_get_contents($path), 'up');

            if ($upBody === null) {
                continue;
            }

            foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                if (preg_match($pattern, $upBody)) {
                    $violations[] = basename($path).' contains a destructive operation matching '.$pattern.' inside up().';
                }
            }
        }

        $this->assertEmpty($violations, "Destructive forward migration operation(s) found:\n".implode("\n", $violations));
    }

    public function test_down_methods_are_not_scanned_and_may_legitimately_drop_what_up_created(): void
    {
        // Sanity check that the extraction itself works and that a
        // representative create_*_table migration's down() (which
        // legitimately calls Schema::dropIfExists()) does not cause a
        // false positive when the up()-only scan above runs.
        $path = database_path('migrations/2026_07_04_100003_create_firms_table.php');
        $this->assertFileExists($path);

        $downBody = $this->extractMethodBody(file_get_contents($path), 'down');
        $this->assertNotNull($downBody);
        $this->assertStringContainsString('dropIfExists', $downBody);

        $upBody = $this->extractMethodBody(file_get_contents($path), 'up');
        $this->assertStringNotContainsString('dropIfExists', $upBody ?? '');
    }

    /**
     * Extracts the source text of `public function {$name}(): void { ... }`
     * by brace-counting from the method signature to its matching
     * closing brace. Good enough for this repo's consistent one-class-
     * per-file anonymous migration convention.
     */
    private function extractMethodBody(string $source, string $methodName): ?string
    {
        if (! preg_match('/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)\s*:\s*void\s*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);
        $pos = $start;

        while ($pos < $length && $depth > 0) {
            $char = $source[$pos];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }

            $pos++;
        }

        return substr($source, $start, $pos - $start - 1);
    }
}
