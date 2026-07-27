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
 *
 * CHECKPOINT 1 UPDATE (FirmsVault Live Integrations,
 * checkpoint1-design-http-ratelimit-usage.md §5,
 * checkpoint1-combined-design.md §4): this test now proves that exactly
 * ONE designated, reviewed file
 * (App\Integrations\Support\ProviderRequestExecutor) may reference a
 * real HTTP client primitive, and that every other file under
 * app/Integrations/ — including any future provider adapter under
 * Providers/{NewProvider}/ — is structurally blocked from doing so
 * independently. The exemption is an exact, suffix-anchored path match,
 * never a substring/basename match, and is proven both minimal (exactly
 * one file matches) and live (the designated file genuinely does
 * reference Http::) by the tests below.
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
     * The sole file anywhere under app/Integrations/ permitted to
     * reference a real HTTP client primitive. Suffix-anchored on the
     * path separator so a same-named file elsewhere, or a file that
     * merely CONTAINS this basename as a substring (e.g.
     * EvilProviderRequestExecutor.php), can never match.
     */
    private const DESIGNATED_REAL_HTTP_CALL_SITE = 'app/Integrations/Support/ProviderRequestExecutor.php';

    private static function isDesignatedRealHttpCallSite(string $absolutePath): bool
    {
        return str_ends_with($absolutePath, DIRECTORY_SEPARATOR.self::DESIGNATED_REAL_HTTP_CALL_SITE);
    }

    /**
     * @return string[] absolute paths to every .php file under
     *                  app/Integrations/, found by walking the
     *                  filesystem directly (no app_path()/container
     *                  dependency, so this stays a pure unit test).
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
            if (self::isDesignatedRealHttpCallSite($file)) {
                continue;
            }

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
            if (self::isDesignatedRealHttpCallSite($file)) {
                continue;
            }

            $source = file_get_contents($file);
            $this->assertIsString($source);

            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+(GuzzleHttp|Illuminate\\\\Support\\\\Facades\\\\Http)\\\\?.*;/m',
                $source,
                "{$file} must not import a real HTTP client class."
            );
        }
    }

    public function test_exactly_one_file_is_the_designated_real_http_call_site(): void
    {
        $files = self::allIntegrationsSourceFiles();
        $matches = array_values(array_filter($files, static fn (string $file): bool => self::isDesignatedRealHttpCallSite($file)));

        $this->assertCount(
            1,
            $matches,
            'Exactly one file may be exempted as the designated real-HTTP-call site — found: '.implode(', ', $matches)
        );
    }

    public function test_the_designated_real_http_call_site_actually_references_the_http_facade(): void
    {
        $files = self::allIntegrationsSourceFiles();
        $designated = array_values(array_filter($files, static fn (string $file): bool => self::isDesignatedRealHttpCallSite($file)));

        $this->assertNotEmpty($designated, 'The designated real-HTTP-call-site file does not exist on disk.');

        $source = file_get_contents($designated[0]);
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'Http::',
            $source,
            'The designated exemption file must genuinely reference Http:: — proving the carve-out is live infrastructure, not a dead/forgotten exemption.'
        );
        $this->assertMatchesRegularExpression(
            '/^use\s+Illuminate\\\\Support\\\\Facades\\\\Http;/m',
            $source,
            'The designated exemption file must genuinely import the Http facade.'
        );
    }

    public function test_the_exemption_helper_does_not_match_a_hypothetical_new_provider_directory(): void
    {
        $hypotheticalFutureProviderFiles = [
            '/home/ubuntu/firmsbase-integration-core/app/Integrations/Providers/Microsoft365/Microsoft365Provider.php',
            '/home/ubuntu/firmsbase-integration-core/app/Integrations/Providers/GoogleWorkspace/GoogleWorkspaceProvider.php',
            '/home/ubuntu/firmsbase-integration-core/app/Integrations/Providers/Plaid/PlaidProvider.php',
            '/home/ubuntu/firmsbase-integration-core/app/Integrations/Providers/LawPay/LawPayProvider.php',
        ];

        foreach ($hypotheticalFutureProviderFiles as $path) {
            $this->assertFalse(
                self::isDesignatedRealHttpCallSite($path),
                "{$path} must NOT be treated as the designated real-HTTP-call site — a future provider adapter must be forced through ProviderRequestExecutor, never granted its own exemption."
            );
        }
    }

    public function test_the_exemption_helper_requires_an_exact_suffix_match_not_a_substring(): void
    {
        $decoyPath = '/home/ubuntu/firmsbase-integration-core/app/Integrations/Support/EvilProviderRequestExecutor.php';

        $this->assertFalse(
            self::isDesignatedRealHttpCallSite($decoyPath),
            'A file that merely CONTAINS the designated basename as a substring must not be exempted — the match must be an exact path suffix.'
        );
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

    /**
     * Security review Finding 7: ProviderRequestExecutor::send() must
     * never use Laravel's PendingRequest::retry() — a `when` callback
     * passed to retry() receives the full, auth-injected $request
     * object (bearer token included), and a naive logging
     * implementation there would bypass every sanitization boundary
     * this domain otherwise enforces. All retry/backoff logic belongs
     * at the job/outbox layer instead.
     */
    public function test_the_designated_real_http_call_site_never_calls_retry(): void
    {
        $files = self::allIntegrationsSourceFiles();
        $designated = array_values(array_filter($files, static fn (string $file): bool => self::isDesignatedRealHttpCallSite($file)));

        $this->assertNotEmpty($designated);

        $source = file_get_contents($designated[0]);
        $this->assertIsString($source);

        $scannable = self::stripCommentsForScanning($source);

        $this->assertDoesNotMatchRegularExpression(
            '/->retry\s*\(/',
            $scannable,
            'ProviderRequestExecutor must never call PendingRequest::retry() — all retry/backoff logic belongs at the job/outbox layer.'
        );
    }
}
