<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderCooldownService;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderCooldownActiveException;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ProviderCooldownServiceTest — checkpoint4-design-cost-control.md §2
 * step 10 / §5.1. Proves cooldown enforcement
 * (`ProviderCooldownActiveException`) and that a cooldown only ever
 * starts on `start()` (the pipeline's own documented "only called from
 * finalize(), only on finalized_billable" rule) — never implicitly.
 */
class ProviderCooldownServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderCooldownService $cooldown;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cooldown = new ProviderCooldownService();
        $this->classifier = new ProviderBillingClassifier();
        Cache::flush();
    }

    private function connection(): FirmIntegration
    {
        $firm = Firm::factory()->create();

        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    public function test_no_cooldown_started_means_zero_remaining_and_no_exception(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $this->assertSame(0, $this->cooldown->remainingSeconds($connection, $classification, null));
        $this->cooldown->assertNotCoolingDown($connection, $classification, $this->fakePolicy(), null);
        $this->addToAssertionCount(1);
    }

    public function test_starting_a_cooldown_makes_a_subsequent_assert_throw_with_the_remaining_seconds(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $this->cooldown->start($connection, $classification, 'account-1', 60);

        $remaining = $this->cooldown->remainingSeconds($connection, $classification, 'account-1');
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(60, $remaining);

        try {
            $this->cooldown->assertNotCoolingDown($connection, $classification, $this->fakePolicy(), 'account-1');
            $this->fail('Expected ProviderCooldownActiveException.');
        } catch (ProviderCooldownActiveException $e) {
            $this->assertGreaterThan(0, $e->remainingSeconds);
        }
    }

    public function test_a_cooldown_started_for_one_account_does_not_apply_to_a_different_account(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $this->cooldown->start($connection, $classification, 'account-1', 60);

        $this->assertSame(0, $this->cooldown->remainingSeconds($connection, $classification, 'account-2'));
        $this->cooldown->assertNotCoolingDown($connection, $classification, $this->fakePolicy(), 'account-2');
        $this->addToAssertionCount(1);
    }

    public function test_a_zero_or_negative_cooldown_seconds_never_starts_a_cooldown(): void
    {
        $connection = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $this->cooldown->start($connection, $classification, 'account-1', 0);

        $this->assertSame(0, $this->cooldown->remainingSeconds($connection, $classification, 'account-1'));
    }

    public function test_a_cooldown_on_a_different_connection_never_applies(): void
    {
        $connectionA = $this->connection();
        $connectionB = $this->connection();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $this->cooldown->start($connectionA, $classification, null, 60);

        $this->assertSame(0, $this->cooldown->remainingSeconds($connectionB, $classification, null));
    }

    private function fakePolicy(): \App\Integrations\Billing\ProviderOperationPolicy
    {
        return new \App\Integrations\Billing\ProviderOperationPolicy(null, null, 86400, 60, null);
    }
}
