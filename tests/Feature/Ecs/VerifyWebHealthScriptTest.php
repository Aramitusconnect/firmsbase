<?php

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves staging-deploy/03-verify-web-health.sh's readiness-check logic
 * (Check 5) handles a non-JSON /readyz failure cleanly — no secondary `jq`
 * parse error — while still requiring HTTP 200 and the expected healthy
 * JSON shape on success.
 *
 * The full script talks to AWS from Check 0 onward, so it cannot be run
 * end-to-end here. Instead, this test extracts the exact "Check 5" block
 * out of the real, committed file (never a hand-retyped copy, so it can
 * never silently drift from what actually ships) and executes it in a
 * subshell against a local test HTTP server standing in for the ALB.
 */
class VerifyWebHealthScriptTest extends TestCase
{
    private ?int $serverPid = null;

    private int $serverPort = 0;

    protected function tearDown(): void
    {
        if ($this->serverPid !== null) {
            @exec('kill '.escapeshellarg((string) $this->serverPid).' 2>/dev/null');
            $this->serverPid = null;
        }

        parent::tearDown();
    }

    private function extractCheck5Block(): string
    {
        $scriptPath = base_path('staging-deploy/03-verify-web-health.sh');
        $contents = file_get_contents($scriptPath);
        $this->assertNotFalse($contents, 'Failed to read 03-verify-web-health.sh');

        $lines = explode("\n", $contents);
        $start = null;
        $end = null;

        foreach ($lines as $index => $line) {
            // Deliberately starts at the UP_CODE= line, not the "Check 5"
            // header — the two lines in between fetch ALB_DNS from AWS,
            // which would overwrite the ALB_DNS this test sets itself to
            // point at a local test server instead of a real ALB.
            if ($start === null && str_starts_with(trim($line), 'UP_CODE=$(curl')) {
                $start = $index;
            }
            if ($start !== null && str_contains($line, '=== Check 6:')) {
                $end = $index;
                break;
            }
        }

        $this->assertNotNull($start, 'Could not locate the "UP_CODE=$(curl" line in 03-verify-web-health.sh — has it been renamed or restructured?');
        $this->assertNotNull($end, 'Could not locate the "Check 6" marker in 03-verify-web-health.sh — has it been renamed?');

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * @return array{0: string, 1: int} [output, fail_value]
     */
    private function runCheck5Against(string $mode): array
    {
        $routerPath = base_path('tests/Feature/Ecs/fixtures/readyz_router.php');
        $this->assertFileExists($routerPath, 'Missing readyz_router.php test fixture.');

        $port = random_int(20000, 40000);
        $serverCommand = sprintf(
            'REPLY_MODE=%s %s -S 127.0.0.1:%d %s > /tmp/readyz_test_server_%d.log 2>&1 & echo $!',
            escapeshellarg($mode),
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($routerPath),
            $port
        );

        $pid = (int) trim((string) shell_exec($serverCommand));
        $this->serverPid = $pid;
        $this->serverPort = $port;

        usleep(400000); // give the built-in server a moment to start listening

        $check5 = $this->extractCheck5Block();

        $harness = 'set -uo pipefail'."\n"
            .'FAIL=0'."\n"
            .'fail() { echo "FAIL: $1" >&2; FAIL=1; }'."\n"
            .'ALB_DNS="127.0.0.1:'.$port.'"'."\n"
            .$check5."\n"
            .'echo "___FAIL_VALUE___=$FAIL"'."\n";

        $tmpScript = tempnam(sys_get_temp_dir(), 'check5_');
        file_put_contents($tmpScript, $harness);

        $output = shell_exec('bash '.escapeshellarg($tmpScript).' 2>&1');
        @unlink($tmpScript);

        @exec('kill '.escapeshellarg((string) $pid).' 2>/dev/null');
        $this->serverPid = null;

        $failValue = 0;
        if (preg_match('/___FAIL_VALUE___=(\d)/', (string) $output, $matches)) {
            $failValue = (int) $matches[1];
        }

        return [(string) $output, $failValue];
    }

    public function test_healthy_json_response_passes_with_no_failure(): void
    {
        [$output, $failValue] = $this->runCheck5Against('healthy');

        $this->assertSame(0, $failValue, "Expected FAIL=0 for a healthy response. Output:\n{$output}");
        $this->assertStringContainsString('readyz database check: ok', $output);
        $this->assertStringContainsString('readyz redis check: ok', $output);
    }

    public function test_non_json_html_failure_stops_cleanly_without_a_secondary_jq_error(): void
    {
        [$output, $failValue] = $this->runCheck5Against('html_500');

        $this->assertSame(1, $failValue, "Expected FAIL=1 for an HTML 500 response. Output:\n{$output}");
        $this->assertStringContainsString('did not return 200', $output);

        // The whole point of this fix: no uncontrolled secondary jq parse
        // error should ever appear, regardless of casing/wording.
        $this->assertStringNotContainsStringIgnoringCase('parse error', $output);
        $this->assertStringNotContainsStringIgnoringCase('jq: error', $output);
        $this->assertStringNotContainsStringIgnoringCase('invalid numeric literal', $output);
    }

    public function test_unhealthy_json_response_reports_the_failed_dependency_cleanly(): void
    {
        [$output, $failValue] = $this->runCheck5Against('unhealthy_json');

        $this->assertSame(1, $failValue, "Expected FAIL=1 for a 503 JSON response. Output:\n{$output}");
        $this->assertStringContainsString('did not return 200', $output);
        $this->assertStringNotContainsStringIgnoringCase('parse error', $output);
    }
}
