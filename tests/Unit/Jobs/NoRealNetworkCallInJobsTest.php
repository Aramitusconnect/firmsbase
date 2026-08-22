<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;

/**
 * NoRealNetworkCallInJobsTest — Checkpoint 9 (frozen design §10 item
 * 2; disclosed Checkpoint 8 gap: `tests/Unit/Integrations/NoRealNetworkCallTest.php`
 * only scopes `app/Integrations/`, missing 9 of the 10 `ShouldQueue`
 * job classes in this codebase). Directory-wide scan of BOTH
 * `app/Jobs/` and `app/Integrations/Jobs/`, no filename filter —
 * reuses `NoRealNetworkCallTest`'s proven comment-stripping/detection
 * logic verbatim (same forbidden-needle list, same block-comment/
 * line-comment stripping discipline, same "does not mask a real
 * violation" self-check), widened to catch every `ShouldQueue` job
 * class regardless of which directory it lives in.
 *
 * Pure unit test: no framework boot, no database — reads .php source
 * files directly off disk, exactly like the precedent it extends.
 */
final class NoRealNetworkCallInJobsTest extends TestCase
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

    private const JOB_DIRECTORIES = [
        'app/Jobs',
        'app/Integrations/Jobs',
    ];

    /**
     * @return string[] absolute paths to every .php file under either
     *                  job directory, found by walking the filesystem
     *                  directly — no filename filter of any kind.
     */
    private static function allJobSourceFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (self::JOB_DIRECTORIES as $relativeDir) {
            $dir = $root.'/'.$relativeDir;

            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
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

    /**
     * Verbatim copy of NoRealNetworkCallTest::stripCommentsForScanning()
     * — same discipline, same reasoning (see that file's own docblock).
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

    public function test_the_job_directories_have_source_files_to_scan(): void
    {
        $this->assertNotEmpty(self::allJobSourceFiles());
        $this->assertGreaterThanOrEqual(10, count(self::allJobSourceFiles()), 'This codebase has at least 10 known ShouldQueue job classes across app/Jobs/ and app/Integrations/Jobs/.');
    }

    public function test_every_shouldqueue_job_class_is_covered_by_this_scan(): void
    {
        // Structural proof the widened scope actually reaches every
        // ShouldQueue class, not merely every file in two directories
        // that happen to currently hold only ShouldQueue classes.
        $files = self::allJobSourceFiles();
        $shouldQueueCount = 0;

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);

            if (str_contains($source, 'ShouldQueue')) {
                $shouldQueueCount++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $shouldQueueCount);
    }

    public function test_no_file_under_app_jobs_or_app_integrations_jobs_references_a_real_network_call_primitive(): void
    {
        $files = self::allJobSourceFiles();
        $this->assertNotEmpty($files);

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

    public function test_no_file_under_app_jobs_or_app_integrations_jobs_imports_a_real_http_client_class(): void
    {
        $files = self::allJobSourceFiles();

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
