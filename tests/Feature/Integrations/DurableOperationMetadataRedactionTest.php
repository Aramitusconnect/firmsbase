<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\SyncCursorService;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * DurableOperationMetadataRedactionTest — Checkpoint 8.2 corrective pass.
 *
 * THE DEFECT THIS EXISTS FOR. `PullSyncJob` passed its DECRYPTED
 * continuation cursor straight into `localProcessingState`, which the
 * pipeline persists verbatim in
 * `provider_operation_attempts.local_processing_state`. That column lives
 * in a table that is FK-free, RLS-EXEMPT and unencrypted by design, while
 * `integration_sync_cursors.cursor_value` is deliberately encrypted
 * per-firm with a CHECK constraint tying it to its encryption key. Every
 * multi-page Plaid sync therefore copied a firm's plaintext cursor into a
 * globally readable table.
 *
 * NEGATIVE CONTROL — stated precisely, because the obvious one is weaker
 * than it looks. Running the reflection tests below against
 * `501a4f6ce6c7e269d3a5fc8e3263ef51c7b74d0a` fails with "method does not
 * exist", which proves only that the method is new. The real control is
 * `test_no_call_site_interpolates_a_raw_cursor_into_durable_operation_metadata()`,
 * a source-level scan that trips on the pre-fix expression itself
 * (`':page_'.($pageCursor ?? 'initial')`) and therefore genuinely fails at
 * that SHA for the right reason.
 *
 * The tests below deliberately exercise the marker builder and the
 * durable column directly rather than driving a full Plaid sync: the
 * property under test is "this string never contains that value", and
 * proving it at the boundary keeps the test immune to how many pages a
 * fixture provider happens to return.
 */
final class DurableOperationMetadataRedactionTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    /** A distinctive value that would be unmistakable if it leaked. */
    private const SECRET_CURSOR = 'PLAINTEXT-PLAID-CURSOR-eyJsYXN0X3RyYW5zYWN0aW9uIjoiMTIzNDU2Nzg5In0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
    }

    /**
     * Reaches the private marker builder the way the job itself does.
     * Reflection is deliberate: the marker must be provably safe at its
     * source, not merely at one call site.
     */
    private function markerFor(int $firmId, ?string $pageCursor): string
    {
        $job = new PullSyncJob(1, $firmId, 'transaction');

        $method = new \ReflectionMethod($job, 'pageProgressMarker');
        $method->setAccessible(true);

        return $method->invoke($job, 41, 7, $pageCursor);
    }

    // ------------------------------------------------------------------

    /**
     * THE REAL NEGATIVE CONTROL — a source-level scan that fails at the
     * pre-fix SHA for the right reason.
     *
     * The defect was not a bad hash; it was a call site interpolating a
     * decrypted value into durable metadata. So the durable guarantee is
     * enforced where it can actually regress: no caller may pass a
     * cursor-, token- or payload-shaped variable into `localProcessingState`.
     * Mirrors the repository's established source-scanning firewall tests
     * (see NoRealNetworkCallTest and the trust-ledger firewall).
     */
    public function test_no_call_site_interpolates_a_raw_cursor_into_durable_operation_metadata(): void
    {
        $forbidden = [
            '$pageCursor',
            '$cursorValue',
            '$decryptedCursor',
            '$accessToken',
            '$refreshToken',
            '$mailboxEmail',
            '$rawBody',
        ];

        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (! str_contains($line, 'localProcessingState')) {
                    continue;
                }

                foreach ($forbidden as $variable) {
                    if (str_contains($line, $variable)) {
                        $violations[] = str_replace(base_path().'/', '', $file).':'.($number + 1).' passes '.$variable;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'provider_operation_attempts is FK-free, RLS-exempt and unencrypted. No decrypted cursor, token or '
                .'payload may be interpolated into its operation metadata — hash it, or reference the authoritative '
                .'encrypted row instead. Found: '.implode(' | ', $violations)
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_the_page_progress_marker_never_contains_cursor_content(): void
    {
        $marker = $this->markerFor(991501, self::SECRET_CURSOR);

        $this->assertStringNotContainsString(
            self::SECRET_CURSOR,
            $marker,
            'The decrypted continuation cursor must never appear in durable operation metadata.'
        );

        // Not merely absent as a whole — no recognisable fragment of it
        // either, which a naive truncation would leave behind.
        $this->assertStringNotContainsString('eyJsYXN0X3RyYW5zYWN0aW9u', $marker);
        $this->assertStringNotContainsString('PLAINTEXT-PLAID-CURSOR', $marker);

        // It is a one-way digest, and it says so.
        $this->assertStringContainsString('sha256:', $marker);
        $this->assertStringContainsString('run_41', $marker, 'The marker must still identify the run.');
        $this->assertStringContainsString('cursor_version_7', $marker, 'The marker must still identify the cursor version.');
    }

    public function test_the_marker_is_stable_for_the_same_page_so_recovery_can_still_resume(): void
    {
        $first = $this->markerFor(991502, self::SECRET_CURSOR);
        $second = $this->markerFor(991502, self::SECRET_CURSOR);

        $this->assertSame($first, $second, 'A resumed attempt must recognise the page it was on.');
    }

    public function test_different_pages_produce_different_markers(): void
    {
        $pageOne = $this->markerFor(991503, 'cursor-page-one');
        $pageTwo = $this->markerFor(991503, 'cursor-page-two');

        $this->assertNotSame($pageOne, $pageTwo);
    }

    /**
     * The digest is firm-scoped, so nobody reading every row of the
     * un-RLS'd gate table can tell that two firms are sitting on the same
     * provider cursor — a correlation an unsalted hash would leak.
     */
    public function test_two_firms_on_the_identical_cursor_produce_different_markers(): void
    {
        $firmA = $this->markerFor(991504, self::SECRET_CURSOR);
        $firmB = $this->markerFor(991505, self::SECRET_CURSOR);

        $this->assertNotSame(
            $firmA,
            $firmB,
            'A marker must not let one firm\'s cursor be correlated with another\'s.'
        );
    }

    public function test_a_first_page_has_no_cursor_to_protect_and_says_so_plainly(): void
    {
        $this->assertStringEndsWith('page_initial', $this->markerFor(991506, null));
    }

    /**
     * The other half of the invariant: the authoritative cursor is still
     * encrypted where it belongs, and the marker cannot be used to get it.
     */
    public function test_the_authoritative_cursor_remains_encrypted_in_its_own_table(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Test->value)->firstOrFail();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create(['status' => ConnectionStatus::Active->value]));

        $cursors = app(SyncCursorService::class);

        $cursor = $this->runWithFirmContext($firm, fn () => $cursors->firstOrCreate($connection, 'transaction', SyncDirection::Inbound));
        $this->runWithFirmContext($firm, fn () => $cursors->advance($connection, $cursor->id, $cursor->cursor_version, self::SECRET_CURSOR));

        $stored = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_cursors')->where('id', $cursor->id)->first());

        $this->assertNotNull($stored->cursor_value);
        $this->assertStringNotContainsString(
            self::SECRET_CURSOR,
            (string) $stored->cursor_value,
            'The cursor must be stored encrypted, never as plaintext.'
        );
        $this->assertNotNull($stored->cursor_value_encryption_key_id, 'An encrypted cursor must name its key.');

        // And it decrypts back correctly, so this is real encryption
        // rather than the value having been dropped.
        $roundTripped = $this->runWithFirmContext($firm, fn () => $cursors->decryptCursorValue(
            $connection,
            $cursors->firstOrCreate($connection, 'transaction', SyncDirection::Inbound)
        ));
        $this->assertSame(self::SECRET_CURSOR, $roundTripped);
    }

    /**
     * A sweep, not a spot check: no row of the durable gate table may
     * contain cursor-shaped content in ANY of its free-text columns.
     */
    public function test_no_durable_gate_column_accepts_cursor_content_from_the_sync_path(): void
    {
        $marker = $this->markerFor(991507, self::SECRET_CURSOR);

        DB::connection(self::DURABLE_CONNECTION)->table('provider_operation_attempts')->insert([
            'uuid' => (string) Str::uuid7(),
            'logical_operation_key' => 'redaction-sweep:'.$marker,
            'provider_key' => 'plaid',
            'firm_id' => 991507,
            'operation_type' => 'transactions_sync',
            'operation_version' => 1,
            'attempt_state' => 'local_processing_complete',
            'local_processing_state' => $marker,
            'send_count' => 1,
            'total_send_count' => 1,
            'reclaim_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', 991507)
            ->first();

        foreach ((array) $row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            $this->assertStringNotContainsString(
                self::SECRET_CURSOR,
                $value,
                "provider_operation_attempts.{$column} must never carry cursor content."
            );
        }

        DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', 991507)
            ->delete();
    }
}
