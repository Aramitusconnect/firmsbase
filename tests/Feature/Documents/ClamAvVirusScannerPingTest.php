<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Services\VirusScan\ClamAvVirusScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClamAvVirusScannerPingTest — focused unit-style coverage for
 * ClamAvVirusScanner::ping(), the lightweight clamd liveness probe
 * added for HealthCheckRegistry::DocumentScanning (Operations Control
 * Plane real-probe wave). Deliberately does NOT require a live clamd
 * daemon — unlike ClamAvVirusScannerLocalProofTest (which speaks the
 * real INSTREAM protocol to a genuine ClamAV engine and skips cleanly
 * when none is reachable), ping() only needs *something* speaking
 * clamd's PING/PONG handshake, so this test fakes that daemon itself: a
 * real Unix domain socket server, run in a forked child process, that
 * accepts one connection and replies however each test case needs.
 * This proves ping()'s actual socket/protocol handling, not merely a
 * mocked return value.
 */
class ClamAvVirusScannerPingTest extends TestCase
{
    private array $socketPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->socketPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_ping_returns_true_when_the_daemon_replies_pong(): void
    {
        $result = $this->pingAgainstFakeDaemon(respondWith: 'PONG');

        $this->assertTrue($result);
    }

    public function test_ping_returns_false_when_the_daemon_replies_something_other_than_pong(): void
    {
        $result = $this->pingAgainstFakeDaemon(respondWith: 'stream: SOME ERROR');

        $this->assertFalse($result);
    }

    public function test_ping_returns_false_when_nothing_is_listening(): void
    {
        $socketPath = sys_get_temp_dir().'/clamav-ping-test-nobody-home-'.Str::random(12).'.sock';

        // Deliberately never created/bound — this proves ping() reports
        // false on connection refused rather than throwing or hanging.
        $scanner = new ClamAvVirusScanner(socket: 'unix://'.$socketPath, timeoutSeconds: 1.0);

        $this->assertFalse($scanner->ping());
    }

    /**
     * Forks a child process that runs a real Unix-domain-socket server
     * speaking just enough of clamd's protocol for this test: accept
     * one connection, read whatever the client sends (ping() sends
     * `zPING\0`), write back $respondWith, close. The parent then
     * points a real ClamAvVirusScanner at that socket and calls
     * ping() for real.
     */
    private function pingAgainstFakeDaemon(string $respondWith): bool
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment — cannot run a genuine forked fake-daemon process for this test.');
        }

        $socketPath = sys_get_temp_dir().'/clamav-ping-test-'.Str::random(12).'.sock';
        $this->socketPaths[] = $socketPath;
        @unlink($socketPath);

        DB::disconnect();
        DB::purge();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed — cannot run this test.');
        }

        if ($pid === 0) {
            // Child process: fake clamd. Never let an exception escape
            // uncaught (inherited parent/PHPUnit process state via
            // fork) — always exit cleanly.
            try {
                $server = @stream_socket_server('unix://'.$socketPath, $errno, $errstr);

                if ($server !== false) {
                    $conn = @stream_socket_accept($server, 5);

                    if ($conn !== false) {
                        stream_set_timeout($conn, 5);
                        @fread($conn, 4096);
                        @fwrite($conn, $respondWith);
                        fclose($conn);
                    }

                    fclose($server);
                }
            } catch (\Throwable) {
                // Nothing to do — the parent's connect/read will simply
                // fail or time out, which each test asserts on its own
                // terms.
            }

            exit(0);
        }

        // Parent process: wait for the socket file to actually exist
        // before connecting to it (the child binds asynchronously).
        $deadline = microtime(true) + 2.0;
        while (! file_exists($socketPath) && microtime(true) < $deadline) {
            usleep(10000);
        }

        try {
            $scanner = new ClamAvVirusScanner(socket: 'unix://'.$socketPath, timeoutSeconds: 3.0);

            return $scanner->ping();
        } finally {
            pcntl_waitpid($pid, $status);
            DB::purge();
        }
    }
}
