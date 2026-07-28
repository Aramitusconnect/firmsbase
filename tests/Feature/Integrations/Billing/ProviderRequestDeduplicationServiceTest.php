<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderRequestDeduplicationService;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ProviderRequestDeduplicationServiceTest — checkpoint4-design-cost-control.md
 * §2 step 9 / §5.2. Proves the pre-flight `Cache::lock()` mechanism
 * genuinely serializes two near-simultaneous requests for the identical
 * (connection, product, billingOperation[, account]) key: the SECOND
 * `acquire()` call, issued while the first lock is still held (the
 * exact shape a double-click or two open browser tabs produces), is
 * denied with `ProviderDuplicateRequestInFlightException` — not merely
 * "the exception class exists," but that Cache::lock() genuinely
 * refuses a second concurrent holder. Also proves the lock releases
 * cleanly (a THIRD acquire, after release, succeeds) and that distinct
 * keys (different connection, different capability, different account)
 * never contend with each other.
 */
class ProviderRequestDeduplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderRequestDeduplicationService $dedup;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dedup = new ProviderRequestDeduplicationService();
        $this->classifier = new ProviderBillingClassifier();
        Cache::flush();
    }

    private function connection(): FirmIntegration
    {
        $firm = Firm::factory()->create();

        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    public function test_a_lone_acquire_succeeds(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $lock = $this->dedup->acquire($connection, $classification);

        $this->assertNotNull($lock);
        $lock->release();
    }

    public function test_a_second_concurrent_acquire_for_the_identical_key_is_denied_while_the_first_still_holds_the_lock(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');
        $accountId = 'account-123';

        // Simulates request #1 (e.g. the first of two near-simultaneous
        // browser tabs) acquiring the pre-flight lock and NOT having
        // released it yet — the exact window a genuine concurrent
        // duplicate request would race into.
        $firstLock = $this->dedup->acquire($connection, $classification, ['account_id' => $accountId]);

        $this->expectException(ProviderDuplicateRequestInFlightException::class);

        try {
            // Request #2, racing in while #1's lock is still held.
            $this->dedup->acquire($connection, $classification, ['account_id' => $accountId]);
        } finally {
            $firstLock->release();
        }
    }

    public function test_after_the_first_lock_is_released_a_new_acquire_succeeds(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $firstLock = $this->dedup->acquire($connection, $classification);
        $firstLock->release();

        $secondLock = $this->dedup->acquire($connection, $classification);

        $this->assertNotNull($secondLock);
        $secondLock->release();
    }

    public function test_different_accounts_on_the_same_connection_and_capability_do_not_contend(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $lockA = $this->dedup->acquire($connection, $classification, ['account_id' => 'account-A']);
        $lockB = $this->dedup->acquire($connection, $classification, ['account_id' => 'account-B']);

        $this->assertNotNull($lockA);
        $this->assertNotNull($lockB);
        $lockA->release();
        $lockB->release();
    }

    public function test_different_connections_never_contend_for_the_same_capability(): void
    {
        $connectionA = $this->connection();
        $connectionB = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $lockA = $this->dedup->acquire($connectionA, $classification);
        $lockB = $this->dedup->acquire($connectionB, $classification);

        $this->assertNotNull($lockA);
        $this->assertNotNull($lockB);
        $lockA->release();
        $lockB->release();
    }

    public function test_different_capabilities_on_the_same_connection_never_contend(): void
    {
        $connection = $this->connection();
        $balance = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');
        $statements = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $lockA = $this->dedup->acquire($connection, $balance);
        $lockB = $this->dedup->acquire($connection, $statements);

        $this->assertNotNull($lockA);
        $this->assertNotNull($lockB);
        $lockA->release();
        $lockB->release();
    }
}
