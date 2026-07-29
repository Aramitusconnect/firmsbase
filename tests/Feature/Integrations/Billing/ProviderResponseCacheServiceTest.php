<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderResponseCacheService;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ProviderResponseCacheServiceTest — checkpoint4-design-cost-control.md
 * §2 step 8. Proves a cacheable operation is served from cache on a
 * hit, that a miss returns null (never a fabricated response), and
 * that Balance ('balance', *) is a structural no-op — never populated,
 * never read — per the design's own explicit statement that Balance's
 * cache-check step is always a structural no-op.
 */
class ProviderResponseCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderResponseCacheService $cache;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ProviderResponseCacheService;
        $this->classifier = new ProviderBillingClassifier;
        Cache::flush();
    }

    private function connection(): FirmIntegration
    {
        $firm = Firm::factory()->create();

        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    public function test_a_miss_returns_null(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->assertNull($this->cache->get($connection, $classification));
    }

    public function test_a_put_then_get_round_trips_the_value(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->cache->put($connection, $classification, [], ['statement_id' => 'abc123'], 60);
        $result = $this->cache->get($connection, $classification);

        $this->assertSame(['statement_id' => 'abc123'], $result);
    }

    public function test_different_key_context_produces_independent_cache_entries(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->cache->put($connection, $classification, ['statement_id' => 'A'], ['value' => 'a'], 60);
        $this->cache->put($connection, $classification, ['statement_id' => 'B'], ['value' => 'b'], 60);

        $this->assertSame(['value' => 'a'], $this->cache->get($connection, $classification, ['statement_id' => 'A']));
        $this->assertSame(['value' => 'b'], $this->cache->get($connection, $classification, ['statement_id' => 'B']));
    }

    public function test_balance_is_a_structural_no_op_never_populated_and_never_read(): void
    {
        $connection = $this->connection();
        $balanceClassification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');
        $this->assertFalse($balanceClassification->isCacheable);

        // put() must silently no-op for Balance — never throw, never store.
        $this->cache->put($connection, $balanceClassification, [], ['available_cents' => 100], 60);

        $this->assertNull($this->cache->get($connection, $balanceClassification));
    }

    public function test_a_ttl_of_zero_or_less_never_stores_anything(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->cache->put($connection, $classification, [], ['value' => 'x'], 0);

        $this->assertNull($this->cache->get($connection, $classification));
    }

    public function test_two_different_connections_never_share_a_cache_entry(): void
    {
        $connectionA = $this->connection();
        $connectionB = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->cache->put($connectionA, $classification, [], ['value' => 'a'], 60);

        $this->assertNull($this->cache->get($connectionB, $classification));
    }
}
