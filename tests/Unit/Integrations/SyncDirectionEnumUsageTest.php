<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Enums\SyncDirection;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * SyncDirectionEnumUsageTest — Checkpoint 6 regression guard
 * (reviews/checkpoint-06/frozen-design-post-review.md §8;
 * agent-6h-test-plan-and-review.md §3.5). Agent 6H's independent review
 * found a real, previously-uncaught implementation risk: Agent 6E's
 * preparation report proposed defining a FRESH two-case SyncDirection
 * enum, unaware that App\Integrations\Enums\SyncDirection already
 * exists from Checkpoint 1 with THREE cases (Inbound/Outbound/
 * Bidirectional) and was already earmarked for Checkpoint 6 consumption.
 * The frozen decision: Checkpoint 6 MUST reuse the existing class
 * as-is — never redefine, narrow, or replace it, and never introduce a
 * second, colliding class of the same name anywhere in
 * App\Integrations\Enums. This test proves that decision held.
 *
 * Pure unit test — no framework boot, no database, no factories.
 */
final class SyncDirectionEnumUsageTest extends TestCase
{
    public function test_it_is_a_string_backed_enum(): void
    {
        $reflection = new ReflectionEnum(SyncDirection::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', (string) $reflection->getBackingType());
    }

    public function test_it_has_exactly_three_cases_with_the_frozen_backed_values(): void
    {
        $cases = SyncDirection::cases();

        $this->assertCount(3, $cases, 'SyncDirection must still have exactly 3 cases — Checkpoint 6 must not narrow it to 2.');

        $this->assertSame(SyncDirection::Inbound, SyncDirection::from('inbound'));
        $this->assertSame(SyncDirection::Outbound, SyncDirection::from('outbound'));
        $this->assertSame(SyncDirection::Bidirectional, SyncDirection::from('bidirectional'));
    }

    public function test_bidirectional_case_still_exists_and_is_deliberately_unused(): void
    {
        // No Checkpoint 6 code path emits Bidirectional (frozen-design-
        // post-review.md §8) — the case simply sits unused, exactly as
        // it has since Checkpoint 1. This test only proves it still
        // EXISTS (was not narrowed away), not that anything emits it.
        $this->assertTrue(SyncDirection::tryFrom('bidirectional') === SyncDirection::Bidirectional);
    }

    /**
     * Proves there is no second declaration of a class/enum literally
     * named SyncDirection anywhere under app/Integrations/Enums/ — a
     * duplicate-class-declaration fatal error is exactly the risk 6H's
     * review closed before implementation began. This is checked by
     * scanning the raw source of every file in that directory for a
     * SECOND `enum SyncDirection` or `class SyncDirection` declaration
     * outside the one canonical file.
     */
    public function test_no_second_sync_direction_class_or_enum_exists_anywhere_in_the_enums_directory(): void
    {
        $root = dirname(__DIR__, 3).'/app/Integrations/Enums';
        $this->assertDirectoryExists($root);

        $canonicalFile = realpath($root.'/SyncDirection.php');
        $this->assertNotFalse($canonicalFile);

        $declarationCount = 0;
        $filesDeclaringIt = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($fileInfo->getPathname());
            $this->assertIsString($source);

            if (preg_match('/\b(enum|class|interface)\s+SyncDirection\b/', $source) === 1) {
                $declarationCount++;
                $filesDeclaringIt[] = $fileInfo->getPathname();
            }
        }

        $this->assertSame(
            1,
            $declarationCount,
            'Exactly one file may declare SyncDirection under app/Integrations/Enums/. Found in: '.implode(', ', $filesDeclaringIt)
        );
        $this->assertSame([$canonicalFile], array_map('realpath', $filesDeclaringIt));
    }

    /**
     * Belt-and-suspenders: also confirm no OTHER enum anywhere in the
     * whole app/ tree declares the literal name SyncDirection (a
     * collision outside app/Integrations/Enums/ would be just as
     * dangerous — any code doing `use X\SyncDirection` could silently
     * resolve to the wrong one depending on import order).
     */
    public function test_no_second_sync_direction_declaration_exists_anywhere_under_app(): void
    {
        $root = dirname(__DIR__, 3).'/app';
        $this->assertDirectoryExists($root);

        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($fileInfo->getPathname());
            $this->assertIsString($source);

            if (preg_match('/\b(enum|class|interface)\s+SyncDirection\b/', $source) === 1) {
                $matches[] = $fileInfo->getPathname();
            }
        }

        $this->assertCount(
            1,
            $matches,
            'Exactly one declaration of SyncDirection may exist anywhere under app/. Found in: '.implode(', ', $matches)
        );
        $this->assertStringContainsString('app/Integrations/Enums/SyncDirection.php', str_replace('\\', '/', $matches[0]));
    }
}
