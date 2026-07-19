<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;

/**
 * Static-analysis-style test, extending the existing
 * tests/Feature/Phase9NoRealProviderCallTest.php precedent's approach
 * (forbidden-substring source scan) to the entire App\Integrations
 * namespace (checkpoint-00-final-specification.md §22).
 *
 * Pure unit test: no framework boot, no database, no factories — this
 * only reads .php source files directly off disk via the filesystem,
 * exactly like the Phase 9 precedent's file_get_contents() approach.
 *
 * Unlike the Phase 9 precedent (which scoped to a handful of glob()
 * patterns), this walks the ENTIRE app/Integrations/ directory tree
 * recursively, so a future checkpoint that adds a new file under any
 * subdirectory (Providers/{NewProvider}/, Support/, etc.) is covered
 * automatically without this test needing to be updated.
 */
final class NoRealNetworkCallTest extends TestCase
{
    private const FORBIDDEN_NEEDLES = [
        'Http::',
        'GuzzleHttp',
        'Guzzle',
        'curl_',
        "fopen('http",
        'fopen("http',
        "file_get_contents('http",
        'file_get_contents("http',
        'fsockopen',
        'stream_socket_client',
    ];

    /**
     * @return string[] absolute paths to every .php file under
     *                   app/Integrations/, found by walking the
     *                   filesystem directly (no app_path()/container
     *                   dependency, so this stays a pure unit test).
     */
    private static function allIntegrationsSourceFiles(): array
    {
        $root = dirname(__DIR__, 3).'/app/Integrations';

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Strips /* ... *\/ block comments (which covers every docblock in
     * this codebase — confirmed by inspection that all multi-line
     * comments here are docblocks) and whole lines that are entirely a
     * // line comment, so a documentation sentence that happens to
     * mention one of the forbidden needles in prose (e.g. "must never
     * be passed to an HTTP client") can never produce a false positive.
     * Deliberately does not attempt to strip a // comment that shares a
     * line with real code, to avoid corrupting a string literal like
     * 'https://...' that legitimately contains "//" mid-line.
     */
    private static function stripCommentsForScanning(string $source): string
    {
        $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $source);
        $lines = explode("\n", $withoutBlockComments ?? $source);

        $codeLines = array_filter(
            $lines,
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '//')
        );

        return implode("\n", $codeLines);
    }

    public function test_the_integrations_namespace_has_source_files_to_scan(): void
    {
        // Sanity check that this test is not vacuously passing over an
        // empty file list.
        $this->assertNotEmpty(self::allIntegrationsSourceFiles());
        $this->assertGreaterThanOrEqual(19, count(self::allIntegrationsSourceFiles()));
    }

    public function test_no_file_under_app_integrations_references_a_real_network_call_primitive(): void
    {
        $files = self::allIntegrationsSourceFiles();

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);

            $scannable = self::stripCommentsForScanning($source);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $scannable,
                    "{$file} must not reference a real network-call primitive: {$needle}"
                );
            }
        }
    }

    public function test_no_file_under_app_integrations_imports_a_real_http_client_class(): void
    {
        // Belt-and-suspenders: also assert no `use` statement anywhere
        // in the namespace imports Illuminate's HTTP client or Guzzle
        // directly.
        $files = self::allIntegrationsSourceFiles();

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);

            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+(GuzzleHttp|Illuminate\\\\Support\\\\Facades\\\\Http)\\\\?.*;/m',
                $source,
                "{$file} must not import a real HTTP client class."
            );
        }
    }

    public function test_comment_stripping_helper_does_not_mask_a_real_violation(): void
    {
        // Proves the comment-stripping in the main test above is not
        // itself a loophole: a forbidden needle placed in actual
        // executable code (not a comment) must still be caught.
        $maliciousSource = <<<'PHP'
            <?php
            /**
             * This docblock safely mentions Http:: and curl_ in prose
             * without being real code.
             */
            final class NotARealFile
            {
                public function doSomething(): void
                {
                    // This inline comment mentions Http:: too, harmlessly.
                    Http::get('https://example.invalid');
                }
            }
            PHP;

        $scannable = self::stripCommentsForScanning($maliciousSource);

        $this->assertStringContainsString(
            'Http::',
            $scannable,
            'The comment-stripping helper must not remove a forbidden needle that appears in real executable code.'
        );
    }

    public function test_comment_stripping_helper_correctly_removes_a_needle_that_only_appears_in_a_comment(): void
    {
        $harmlessSource = <<<'PHP'
            <?php
            /**
             * Never call Http:: or curl_ here — this is documentation only.
             */
            final class NotARealFile
            {
                // Http:: mentioned again, purely as a line comment.
                public function doSomething(): void
                {
                    return;
                }
            }
            PHP;

        $scannable = self::stripCommentsForScanning($harmlessSource);

        $this->assertStringNotContainsString('Http::', $scannable);
        $this->assertStringNotContainsString('curl_', $scannable);
    }
}
